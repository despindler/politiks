from __future__ import annotations

import json
import sqlite3
from pathlib import Path

import pytest

from politiks.classification import (
    ProposedSuggestion,
    apply_review_file,
    classify_benchmark_text,
    rebuild_vote_search,
    run_deterministic_classification,
    run_model_classification,
    search_votes,
)
from politiks.importer import recreate_database


ROOT = Path(__file__).resolve().parents[2]
TAXONOMY = ROOT / "classification/taxonomy/v1.de.json"
RULES = ROOT / "classification/rules/v1.de.json"
BENCHMARK = ROOT / "classification/benchmark/v1.de.json"


@pytest.fixture(scope="module")
def classified_database(tmp_path_factory: pytest.TempPathFactory) -> Path:
    database = tmp_path_factory.mktemp("classification") / "fixture.sqlite"
    recreate_database(ROOT, database_path=database)
    connection = sqlite3.connect(database)
    connection.row_factory = sqlite3.Row
    try:
        with connection:
            first = run_deterministic_classification(connection, TAXONOMY, RULES)
            second = run_deterministic_classification(connection, TAXONOMY, RULES)
            rebuild_vote_search(connection)
        assert first.run_key == second.run_key
        assert first.suggestions == second.suggestions
    finally:
        connection.close()
    return database


def connect(database: Path) -> sqlite3.Connection:
    connection = sqlite3.connect(database)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    return connection


def test_benchmark_keeps_ambiguous_and_procedural_effects_unforced() -> None:
    benchmark = json.loads(BENCHMARK.read_text(encoding="utf-8"))
    for case in benchmark["cases"]:
        suggestions = classify_benchmark_text(TAXONOMY, RULES, case["text"])
        rule_ids = {suggestion.rule_id for suggestion in suggestions}
        relationships = {suggestion.relationship for suggestion in suggestions}
        assert set(case["required_rule_ids"]) <= rule_ids
        assert not (set(case["forbidden_relationships"]) & relationships)


def test_deterministic_suggestions_remain_pending(classified_database: Path) -> None:
    connection = connect(classified_database)
    try:
        run = connection.execute(
            "SELECT method, ruleset_sha256, configuration_json, status FROM classification_run"
        ).fetchone()
        assert dict(run) == {
            "method": "deterministic",
            "ruleset_sha256": run["ruleset_sha256"],
            "configuration_json": '{"field_order":["official_metadata","title","question","semantics","official_text"]}',
            "status": "completed",
        }
        assert len(run["ruleset_sha256"]) == 64
        assert connection.execute("SELECT COUNT(*) FROM classification_suggestion").fetchone()[0] > 0
        assert connection.execute("SELECT COUNT(*) FROM classification_review").fetchone()[0] == 0
        assert connection.execute("SELECT COUNT(*) FROM reviewed_classification").fetchone()[0] == 0
    finally:
        connection.close()


def test_exact_identifier_search_does_not_depend_on_classification(
    classified_database: Path,
) -> None:
    connection = connect(classified_database)
    try:
        canonical = search_votes(connection, "20130468", 5)
        displayed = search_votes(connection, "13.468", 5)
        by_date = search_votes(connection, "2020-12-18", 5)
        by_text = search_votes(connection, "Schlussabstimmung", 5)
        assert canonical and all(row["match_type"] == "exact" for row in canonical)
        assert displayed and all(row["match_type"] == "exact" for row in displayed)
        assert by_date and by_date[0]["match_type"] == "exact"
        assert by_text and by_text[0]["match_type"] == "full_text"
        assert all(not row["reviewed_classifications"] for row in canonical)
    finally:
        connection.close()


def test_review_history_controls_the_reviewed_view(
    classified_database: Path, tmp_path: Path
) -> None:
    connection = connect(classified_database)
    try:
        suggestion_key = connection.execute(
            "SELECT suggestion_key FROM classification_suggestion ORDER BY suggestion_key LIMIT 1"
        ).fetchone()[0]
        review_path = tmp_path / "reviews.jsonl"
        accepted = {
            "suggestion_key": suggestion_key,
            "revision": 1,
            "decision": "accepted",
            "reviewer": "test-reviewer",
            "reviewed_at": "2026-07-14T12:00:00Z",
        }
        review_path.write_text(json.dumps(accepted) + "\n", encoding="utf-8")
        with connection:
            assert apply_review_file(connection, review_path) == 1
        assert connection.execute("SELECT COUNT(*) FROM reviewed_classification").fetchone()[0] == 1
        published = connection.execute(
            """SELECT source_snapshot, classification_method, taxonomy_version_id,
                      evidence_passage, reviewer
               FROM reviewed_classification"""
        ).fetchone()
        assert published["source_snapshot"] == "fixture"
        assert published["classification_method"] == "deterministic"
        assert published["taxonomy_version_id"] is not None
        assert published["evidence_passage"]
        assert published["reviewer"] == "test-reviewer"

        rejected = {
            "suggestion_key": suggestion_key,
            "revision": 2,
            "decision": "rejected",
            "reviewer": "test-reviewer",
            "reviewed_at": "2026-07-14T12:05:00Z",
            "notes": "Latest decision supersedes the acceptance.",
        }
        review_path.write_text(
            json.dumps(accepted) + "\n" + json.dumps(rejected) + "\n", encoding="utf-8"
        )
        with connection:
            assert apply_review_file(connection, review_path) == 1
        assert connection.execute("SELECT COUNT(*) FROM classification_review").fetchone()[0] == 2
        assert connection.execute("SELECT COUNT(*) FROM reviewed_classification").fetchone()[0] == 0

        skipped_revision = {
            "suggestion_key": suggestion_key,
            "revision": 4,
            "decision": "accepted",
            "reviewer": "test-reviewer",
            "reviewed_at": "2026-07-14T12:10:00Z",
        }
        review_path.write_text(
            json.dumps(accepted)
            + "\n"
            + json.dumps(rejected)
            + "\n"
            + json.dumps(skipped_revision)
            + "\n",
            encoding="utf-8",
        )
        with pytest.raises(ValueError, match="must follow 2"):
            apply_review_file(connection, review_path)
    finally:
        connection.close()


def test_model_interface_records_provenance_but_cannot_publish(
    classified_database: Path,
) -> None:
    class FakeProvider:
        provider_name = "test-provider"
        model_name = "test-model"

        def suggest(self, document):
            return [
                ProposedSuggestion(
                    dimension="policy_topic",
                    term="public_finance_tax",
                    relationship="topic",
                    effect_direction="not_applicable",
                    directness="not_applicable",
                    confidence=0.51,
                    evidence_field="title",
                    evidence_passage=document.fields.get("title") or "synthetic evidence",
                    rule_id="fake-provider-output",
                )
            ]

    connection = connect(classified_database)
    try:
        reviewed_before = connection.execute(
            "SELECT COUNT(*) FROM reviewed_classification"
        ).fetchone()[0]
        with connection:
            report = run_model_classification(
                connection,
                TAXONOMY,
                FakeProvider(),
                prompt_version="test-v1",
                configuration={"temperature": 0},
                target_limit=1,
            )
        model_run = connection.execute(
            """SELECT provider, model, prompt_version, configuration_json
               FROM classification_run WHERE method='model'"""
        ).fetchone()
        assert report.suggestions == 1
        assert dict(model_run) == {
            "provider": "test-provider",
            "model": "test-model",
            "prompt_version": "test-v1",
            "configuration_json": '{"temperature":0}',
        }
        assert (
            connection.execute("SELECT COUNT(*) FROM reviewed_classification").fetchone()[0]
            == reviewed_before
        )
    finally:
        connection.close()

"""Auditable classification, review, and vote-search helpers."""

from __future__ import annotations

import hashlib
import html
import json
import re
import sqlite3
import unicodedata
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Protocol


DIMENSIONS = {"policy_topic", "affected_group", "effect_mechanism"}
RELATIONSHIPS = {"topic", "affected", "beneficiary", "cost_bearer", "mechanism"}
EFFECT_DIRECTIONS = {
    "benefit",
    "cost",
    "increase",
    "decrease",
    "mixed",
    "unclear",
    "not_applicable",
}
DIRECTNESS = {"direct", "indirect", "claimed", "mixed", "unclear", "not_applicable"}
RELATIONSHIPS_BY_DIMENSION = {
    "policy_topic": {"topic"},
    "affected_group": {"affected", "beneficiary", "cost_bearer"},
    "effect_mechanism": {"mechanism"},
}


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def sha256_bytes(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def normalized_text(value: Any) -> str:
    text = "" if value is None else html.unescape(str(value))
    text = re.sub(r"<[^>]+>", " ", text)
    text = unicodedata.normalize("NFC", text.casefold())
    return " ".join(text.split())


@dataclass(frozen=True)
class TargetDocument:
    target_kind: str
    target_id: int
    source_record_id: int | None
    fields: dict[str, str]


@dataclass(frozen=True)
class ProposedSuggestion:
    dimension: str
    term: str
    relationship: str
    effect_direction: str
    directness: str
    confidence: float
    evidence_field: str
    evidence_passage: str
    rule_id: str | None = None


class ModelSuggestionProvider(Protocol):
    """Provider-neutral opt-in interface; implementations may not publish reviews."""

    provider_name: str
    model_name: str

    def suggest(self, document: TargetDocument) -> Iterable[ProposedSuggestion]: ...


@dataclass(frozen=True)
class ClassificationReport:
    run_key: str
    targets: int
    suggestions: int
    by_dimension: dict[str, int]


def _load_json(path: Path) -> tuple[dict[str, Any], str]:
    content = path.read_bytes()
    value = json.loads(content.decode("utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"Expected an object in {path}")
    return value, sha256_bytes(content)


def install_taxonomy(
    connection: sqlite3.Connection, taxonomy_path: Path
) -> tuple[int, dict[tuple[str, str], int], dict[str, Any], str]:
    taxonomy, digest = _load_json(taxonomy_path)
    version = str(taxonomy.get("version") or "")
    language = str(taxonomy.get("language") or "")
    title = str(taxonomy.get("title") or "")
    status = str(taxonomy.get("status") or "")
    created_at = str(taxonomy.get("created_at") or "")
    if not all((version, language, title, created_at)) or status not in {"draft", "active", "retired"}:
        raise ValueError("Taxonomy metadata is incomplete or invalid")

    existing = connection.execute(
        "SELECT id, definition_sha256 FROM taxonomy_version WHERE version=?", (version,)
    ).fetchone()
    if existing and str(existing["definition_sha256"]) != digest:
        raise ValueError(f"Taxonomy version {version} already exists with different bytes")
    if existing:
        taxonomy_id = int(existing["id"])
    else:
        cursor = connection.execute(
            """INSERT INTO taxonomy_version
               (version, language, title, status, definition_sha256, created_at)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (version, language, title, status, digest, created_at),
        )
        taxonomy_id = int(cursor.lastrowid)

    vocabularies = {
        "effect_directions": EFFECT_DIRECTIONS,
        "directness": DIRECTNESS,
        "review_statuses": {"pending", "accepted", "edited", "rejected"},
    }
    for key, required in vocabularies.items():
        supplied = {str(item.get("code")) for item in taxonomy.get(key, [])}
        if supplied != required:
            raise ValueError(f"Taxonomy vocabulary {key} must define exactly {sorted(required)}")

    term_ids: dict[tuple[str, str], int] = {}
    dimensions = taxonomy.get("dimensions")
    if not isinstance(dimensions, dict) or set(dimensions) != DIMENSIONS:
        raise ValueError(f"Taxonomy dimensions must be exactly {sorted(DIMENSIONS)}")
    for dimension, terms in dimensions.items():
        if not isinstance(terms, list) or not terms:
            raise ValueError(f"Taxonomy dimension {dimension} is empty")
        seen: set[str] = set()
        for order, term in enumerate(terms):
            code = str(term.get("code") or "")
            if not code or code in seen:
                raise ValueError(f"Duplicate or missing term code in {dimension}: {code!r}")
            seen.add(code)
            connection.execute(
                """INSERT OR IGNORE INTO taxonomy_term
                   (taxonomy_version_id, dimension, code, parent_code, name_de,
                    description_de, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (
                    taxonomy_id,
                    dimension,
                    code,
                    term.get("parent_code"),
                    str(term.get("name_de") or ""),
                    str(term.get("description_de") or ""),
                    order,
                ),
            )
            row = connection.execute(
                """SELECT id FROM taxonomy_term
                   WHERE taxonomy_version_id=? AND dimension=? AND code=?""",
                (taxonomy_id, dimension, code),
            ).fetchone()
            term_ids[(dimension, code)] = int(row["id"])
    return taxonomy_id, term_ids, taxonomy, digest


def _source_snapshot(connection: sqlite3.Connection) -> str:
    rows = connection.execute(
        "SELECT snapshot_name FROM import_run WHERE status='completed' ORDER BY id"
    ).fetchall()
    if len(rows) != 1:
        raise ValueError("Classification requires exactly one completed source import")
    return str(rows[0][0])


def _target_documents(connection: sqlite3.Connection) -> Iterable[TargetDocument]:
    query = """
    SELECT
      event.id AS target_id,
      event.source_record_id,
      matter.formatted_identifier,
      matter.source_identifier AS affair_source_identifier,
      matter.title,
      event.source_identifier AS voting_identifier,
      event.registration_number,
      event.occurred_at,
      event.division_text,
      event.submission_text,
      event.meaning_yes,
      event.meaning_no,
      event.vote_type,
      source.raw_json,
      (SELECT group_concat(topic.name, ' | ')
         FROM matter_topic link JOIN official_topic topic ON topic.id=link.topic_id
        WHERE link.matter_id=matter.id) AS official_topics,
      (SELECT group_concat(descriptor.name, ' | ')
         FROM matter_descriptor link JOIN official_descriptor descriptor ON descriptor.id=link.descriptor_id
        WHERE link.matter_id=matter.id) AS official_descriptors,
      (SELECT group_concat(body_html, ' | ') FROM matter_text WHERE matter_id=matter.id) AS matter_texts,
      (SELECT group_concat(
          coalesce(description_html,'') || ' ' || coalesce(initial_situation_html,'') || ' ' || coalesce(proceedings_html,''),
          ' | '
       ) FROM matter_summary WHERE matter_id=matter.id) AS matter_summaries
    FROM voting_event event
    LEFT JOIN parliamentary_matter matter ON matter.id=event.matter_id
    LEFT JOIN source_record source ON source.id=event.source_record_id
    ORDER BY event.id
    """
    for row in connection.execute(query):
        committee = ""
        raw_json = row["raw_json"]
        if raw_json:
            try:
                committee = str(json.loads(raw_json).get("committee") or "")
            except (json.JSONDecodeError, AttributeError):
                committee = ""
        fields = {
            "identifier": " ".join(
                str(value)
                for value in (
                    row["formatted_identifier"],
                    row["affair_source_identifier"],
                    row["voting_identifier"],
                    row["registration_number"],
                )
                if value not in (None, "")
            ),
            "title": str(row["title"] or ""),
            "question": " | ".join(
                str(value)
                for value in (row["division_text"], row["submission_text"])
                if value not in (None, "")
            ),
            "semantics": " | ".join(
                str(value)
                for value in (row["meaning_yes"], row["meaning_no"])
                if value not in (None, "")
            ),
            "official_metadata": " | ".join(
                str(value)
                for value in (
                    row["official_topics"],
                    row["official_descriptors"],
                    committee,
                    row["vote_type"],
                )
                if value not in (None, "")
            ),
            "official_text": " | ".join(
                normalized_text(value)
                for value in (row["matter_texts"], row["matter_summaries"])
                if value not in (None, "")
            ),
        }
        yield TargetDocument(
            target_kind="voting_event",
            target_id=int(row["target_id"]),
            source_record_id=int(row["source_record_id"]) if row["source_record_id"] else None,
            fields=fields,
        )


def _validate_proposal(
    proposal: ProposedSuggestion, term_ids: dict[tuple[str, str], int]
) -> int:
    if proposal.dimension not in DIMENSIONS:
        raise ValueError(f"Unknown suggestion dimension {proposal.dimension}")
    term_id = term_ids.get((proposal.dimension, proposal.term))
    if term_id is None:
        raise ValueError(f"Unknown taxonomy term {proposal.dimension}:{proposal.term}")
    if proposal.relationship not in RELATIONSHIPS:
        raise ValueError(f"Unknown relationship {proposal.relationship}")
    if proposal.relationship not in RELATIONSHIPS_BY_DIMENSION[proposal.dimension]:
        raise ValueError(
            f"Relationship {proposal.relationship} is invalid for {proposal.dimension}"
        )
    if proposal.effect_direction not in EFFECT_DIRECTIONS:
        raise ValueError(f"Unknown effect direction {proposal.effect_direction}")
    if proposal.directness not in DIRECTNESS:
        raise ValueError(f"Unknown directness {proposal.directness}")
    if proposal.relationship == "topic" and (
        proposal.effect_direction != "not_applicable" or proposal.directness != "not_applicable"
    ):
        raise ValueError("Policy topics cannot assert an effect or directness")
    if proposal.relationship == "beneficiary" and proposal.effect_direction != "benefit":
        raise ValueError("A beneficiary suggestion must use the benefit direction")
    if proposal.relationship == "cost_bearer" and proposal.effect_direction != "cost":
        raise ValueError("A cost-bearer suggestion must use the cost direction")
    if not 0 <= proposal.confidence <= 1:
        raise ValueError("Suggestion confidence must be between zero and one")
    if not proposal.evidence_field or not proposal.evidence_passage:
        raise ValueError("Every suggestion requires an evidence field and passage")
    return term_id


def _evidence_passage(text: str, match: re.Match[str], radius: int = 100) -> str:
    start = max(0, match.start() - radius)
    end = min(len(text), match.end() + radius)
    prefix = "…" if start else ""
    suffix = "…" if end < len(text) else ""
    return f"{prefix}{' '.join(text[start:end].split())}{suffix}"


def _insert_suggestion(
    connection: sqlite3.Connection,
    run_id: int,
    run_key: str,
    document: TargetDocument,
    proposal: ProposedSuggestion,
    term_id: int,
) -> bool:
    key_material = canonical_json(
        {
            "run": run_key,
            "target_kind": document.target_kind,
            "target_id": document.target_id,
            "dimension": proposal.dimension,
            "term": proposal.term,
            "relationship": proposal.relationship,
            "effect_direction": proposal.effect_direction,
            "directness": proposal.directness,
            "rule_id": proposal.rule_id,
        }
    ).encode("utf-8")
    suggestion_key = hashlib.sha256(key_material).hexdigest()
    cursor = connection.execute(
        """INSERT OR IGNORE INTO classification_suggestion
           (suggestion_key, classification_run_id, target_kind, matter_id,
            voting_event_id, taxonomy_term_id, relationship, effect_direction,
            directness, confidence, evidence_field, evidence_passage, rule_id,
            source_record_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            suggestion_key,
            run_id,
            document.target_kind,
            document.target_id if document.target_kind == "matter" else None,
            document.target_id if document.target_kind == "voting_event" else None,
            term_id,
            proposal.relationship,
            proposal.effect_direction,
            proposal.directness,
            proposal.confidence,
            proposal.evidence_field,
            proposal.evidence_passage,
            proposal.rule_id,
            document.source_record_id,
        ),
    )
    return cursor.rowcount > 0


def run_deterministic_classification(
    connection: sqlite3.Connection,
    taxonomy_path: Path,
    rules_path: Path,
) -> ClassificationReport:
    taxonomy_id, term_ids, _taxonomy, taxonomy_digest = install_taxonomy(
        connection, taxonomy_path
    )
    ruleset, rules_digest = _load_json(rules_path)
    rules = ruleset.get("rules")
    if not isinstance(rules, list) or not rules:
        raise ValueError("Ruleset contains no rules")
    compiled_rules: list[tuple[dict[str, Any], list[re.Pattern[str]]]] = []
    seen_rule_ids: set[str] = set()
    for rule in rules:
        rule_id = str(rule.get("id") or "")
        if not rule_id or rule_id in seen_rule_ids:
            raise ValueError(f"Duplicate or missing classification rule id {rule_id!r}")
        seen_rule_ids.add(rule_id)
        proposal = ProposedSuggestion(
            dimension=str(rule.get("dimension") or ""),
            term=str(rule.get("term") or ""),
            relationship=str(rule.get("relationship") or ""),
            effect_direction=str(rule.get("effect_direction") or ""),
            directness=str(rule.get("directness") or ""),
            confidence=float(rule.get("confidence")),
            evidence_field="validation",
            evidence_passage="validation",
            rule_id=rule_id,
        )
        _validate_proposal(proposal, term_ids)
        patterns = rule.get("patterns")
        if not isinstance(patterns, list) or not patterns:
            raise ValueError(f"Rule {rule_id} has no patterns")
        compiled_rules.append((rule, [re.compile(str(pattern), re.IGNORECASE) for pattern in patterns]))

    snapshot = _source_snapshot(connection)
    configuration = {"field_order": ["official_metadata", "title", "question", "semantics", "official_text"]}
    run_key = hashlib.sha256(
        canonical_json(
            {
                "method": "deterministic",
                "snapshot": snapshot,
                "taxonomy_sha256": taxonomy_digest,
                "rules_sha256": rules_digest,
                "configuration": configuration,
            }
        ).encode("utf-8")
    ).hexdigest()
    connection.execute(
        """INSERT OR IGNORE INTO classification_run
           (run_key, taxonomy_version_id, source_snapshot, method, ruleset_version,
            ruleset_sha256, provider, model, configuration_json, prompt_version,
            started_at, completed_at, status)
           VALUES (?, ?, ?, 'deterministic', ?, ?, NULL, NULL, ?, NULL, ?, NULL, 'running')""",
        (
            run_key,
            taxonomy_id,
            snapshot,
            str(ruleset.get("version") or ""),
            rules_digest,
            canonical_json(configuration),
            utc_now(),
        ),
    )
    run_row = connection.execute(
        "SELECT id FROM classification_run WHERE run_key=?", (run_key,)
    ).fetchone()
    run_id = int(run_row["id"])

    targets = 0
    for document in _target_documents(connection):
        targets += 1
        normalized_fields = {
            field: normalized_text(value) for field, value in document.fields.items() if value
        }
        for rule, patterns in compiled_rules:
            match_field = None
            match_value = None
            match_object = None
            for field in configuration["field_order"]:
                value = normalized_fields.get(field, "")
                for pattern in patterns:
                    found = pattern.search(value)
                    if found:
                        match_field, match_value, match_object = field, value, found
                        break
                if match_object:
                    break
            if match_object is None or match_field is None or match_value is None:
                continue
            proposal = ProposedSuggestion(
                dimension=str(rule["dimension"]),
                term=str(rule["term"]),
                relationship=str(rule["relationship"]),
                effect_direction=str(rule["effect_direction"]),
                directness=str(rule["directness"]),
                confidence=float(rule["confidence"]),
                evidence_field=match_field,
                evidence_passage=_evidence_passage(match_value, match_object),
                rule_id=str(rule["id"]),
            )
            term_id = _validate_proposal(proposal, term_ids)
            _insert_suggestion(connection, run_id, run_key, document, proposal, term_id)

    connection.execute(
        "UPDATE classification_run SET completed_at=?, status='completed' WHERE id=?",
        (utc_now(), run_id),
    )
    by_dimension = {
        str(row["dimension"]): int(row["amount"])
        for row in connection.execute(
            """SELECT term.dimension, COUNT(*) AS amount
               FROM classification_suggestion suggestion
               JOIN taxonomy_term term ON term.id=suggestion.taxonomy_term_id
               WHERE suggestion.classification_run_id=?
               GROUP BY term.dimension ORDER BY term.dimension""",
            (run_id,),
        )
    }
    suggestions = sum(by_dimension.values())
    return ClassificationReport(run_key, targets, suggestions, by_dimension)


def classify_benchmark_text(
    taxonomy_path: Path, rules_path: Path, text: str
) -> list[ProposedSuggestion]:
    """Apply the committed deterministic rules to one synthetic benchmark text."""

    taxonomy, _taxonomy_digest = _load_json(taxonomy_path)
    known_terms = {
        (dimension, str(term.get("code")))
        for dimension, terms in (taxonomy.get("dimensions") or {}).items()
        for term in terms
    }
    ruleset, _rules_digest = _load_json(rules_path)
    normalized = normalized_text(text)
    suggestions: list[ProposedSuggestion] = []
    for rule in ruleset.get("rules") or []:
        dimension = str(rule.get("dimension") or "")
        term = str(rule.get("term") or "")
        if (dimension, term) not in known_terms:
            raise ValueError(f"Rule references unknown taxonomy term {dimension}:{term}")
        found = None
        for pattern in rule.get("patterns") or []:
            found = re.search(str(pattern), normalized, re.IGNORECASE)
            if found:
                break
        if found is None:
            continue
        suggestions.append(
            ProposedSuggestion(
                dimension=dimension,
                term=term,
                relationship=str(rule.get("relationship") or ""),
                effect_direction=str(rule.get("effect_direction") or ""),
                directness=str(rule.get("directness") or ""),
                confidence=float(rule.get("confidence")),
                evidence_field="benchmark_text",
                evidence_passage=_evidence_passage(normalized, found),
                rule_id=str(rule.get("id") or ""),
            )
        )
    return suggestions


def run_model_classification(
    connection: sqlite3.Connection,
    taxonomy_path: Path,
    provider: ModelSuggestionProvider,
    *,
    prompt_version: str,
    configuration: dict[str, Any],
    target_limit: int | None = None,
) -> ClassificationReport:
    """Record optional provider suggestions as pending; never create a review."""

    taxonomy_id, term_ids, _taxonomy, taxonomy_digest = install_taxonomy(
        connection, taxonomy_path
    )
    snapshot = _source_snapshot(connection)
    run_key = hashlib.sha256(
        canonical_json(
            {
                "method": "model",
                "snapshot": snapshot,
                "taxonomy_sha256": taxonomy_digest,
                "provider": provider.provider_name,
                "model": provider.model_name,
                "prompt_version": prompt_version,
                "configuration": configuration,
            }
        ).encode("utf-8")
    ).hexdigest()
    connection.execute(
        """INSERT OR IGNORE INTO classification_run
           (run_key, taxonomy_version_id, source_snapshot, method, ruleset_version,
            ruleset_sha256, provider, model, configuration_json, prompt_version,
            started_at, completed_at, status)
           VALUES (?, ?, ?, 'model', NULL, NULL, ?, ?, ?, ?, ?, NULL, 'running')""",
        (
            run_key,
            taxonomy_id,
            snapshot,
            provider.provider_name,
            provider.model_name,
            canonical_json(configuration),
            prompt_version,
            utc_now(),
        ),
    )
    run_id = int(
        connection.execute("SELECT id FROM classification_run WHERE run_key=?", (run_key,)).fetchone()[
            "id"
        ]
    )
    targets = 0
    for document in _target_documents(connection):
        if target_limit is not None and targets >= target_limit:
            break
        targets += 1
        for proposal in provider.suggest(document):
            term_id = _validate_proposal(proposal, term_ids)
            source_field = document.fields.get(proposal.evidence_field)
            if not source_field or normalized_text(proposal.evidence_passage) not in normalized_text(
                source_field
            ):
                raise ValueError("Model suggestion evidence passage is not present in its source field")
            _insert_suggestion(connection, run_id, run_key, document, proposal, term_id)
    connection.execute(
        "UPDATE classification_run SET completed_at=?, status='completed' WHERE id=?",
        (utc_now(), run_id),
    )
    by_dimension = {
        str(row["dimension"]): int(row["amount"])
        for row in connection.execute(
            """SELECT term.dimension, COUNT(*) AS amount
               FROM classification_suggestion suggestion
               JOIN taxonomy_term term ON term.id=suggestion.taxonomy_term_id
               WHERE suggestion.classification_run_id=?
               GROUP BY term.dimension ORDER BY term.dimension""",
            (run_id,),
        )
    }
    return ClassificationReport(run_key, targets, sum(by_dimension.values()), by_dimension)


def apply_review_file(connection: sqlite3.Connection, review_path: Path) -> int:
    """Apply an append-only controlled JSONL review file idempotently."""

    content = review_path.read_bytes()
    digest = sha256_bytes(content)
    applied = 0
    seen: set[tuple[str, int]] = set()
    for line_number, raw_line in enumerate(content.decode("utf-8").splitlines(), start=1):
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue
        try:
            review = json.loads(raw_line)
        except json.JSONDecodeError as error:
            raise ValueError(f"Invalid review JSON at {review_path}:{line_number}: {error}") from error
        suggestion_key = str(review.get("suggestion_key") or "")
        revision = int(review.get("revision") or 0)
        decision = str(review.get("decision") or "")
        identity = (suggestion_key, revision)
        if not suggestion_key or revision < 1 or decision not in {"accepted", "edited", "rejected"}:
            raise ValueError(f"Invalid review identity or decision at {review_path}:{line_number}")
        reviewer = str(review.get("reviewer") or "").strip()
        reviewed_at = str(review.get("reviewed_at") or "").strip()
        if not reviewer or not reviewed_at:
            raise ValueError(f"Review lacks reviewer or timestamp at {review_path}:{line_number}")
        if identity in seen:
            raise ValueError(f"Duplicate review {identity} in {review_path}")
        seen.add(identity)
        suggestion = connection.execute(
            """SELECT suggestion.id, run.taxonomy_version_id
               FROM classification_suggestion suggestion
               JOIN classification_run run ON run.id=suggestion.classification_run_id
               WHERE suggestion.suggestion_key=?""",
            (suggestion_key,),
        ).fetchone()
        if suggestion is None:
            raise ValueError(f"Unknown suggestion_key at {review_path}:{line_number}")

        replacement_term_id = None
        replacement_relationship = None
        replacement_direction = None
        replacement_directness = None
        if decision == "edited":
            replacement = review.get("replacement")
            if not isinstance(replacement, dict):
                raise ValueError(f"Edited review lacks replacement at {review_path}:{line_number}")
            dimension = str(replacement.get("dimension") or "")
            term = str(replacement.get("term") or "")
            term_row = connection.execute(
                """SELECT id FROM taxonomy_term
                   WHERE taxonomy_version_id=? AND dimension=? AND code=?""",
                (suggestion["taxonomy_version_id"], dimension, term),
            ).fetchone()
            if term_row is None:
                raise ValueError(f"Unknown replacement term at {review_path}:{line_number}")
            replacement_term_id = int(term_row["id"])
            replacement_relationship = str(replacement.get("relationship") or "")
            replacement_direction = str(replacement.get("effect_direction") or "")
            replacement_directness = str(replacement.get("directness") or "")
            if (
                replacement_relationship not in RELATIONSHIPS
                or replacement_direction not in EFFECT_DIRECTIONS
                or replacement_directness not in DIRECTNESS
            ):
                raise ValueError(f"Invalid replacement vocabulary at {review_path}:{line_number}")
            replacement_proposal = ProposedSuggestion(
                dimension=dimension,
                term=term,
                relationship=replacement_relationship,
                effect_direction=replacement_direction,
                directness=replacement_directness,
                confidence=1,
                evidence_field="reviewed_source_evidence",
                evidence_passage="reviewed_source_evidence",
            )
            term_ids = {
                (str(row["dimension"]), str(row["code"])): int(row["id"])
                for row in connection.execute(
                    "SELECT id, dimension, code FROM taxonomy_term WHERE taxonomy_version_id=?",
                    (suggestion["taxonomy_version_id"],),
                )
            }
            _validate_proposal(replacement_proposal, term_ids)

        record_digest = sha256_bytes(raw_line.encode("utf-8"))
        existing = connection.execute(
            """SELECT review_record_sha256 FROM classification_review
               WHERE classification_suggestion_id=? AND revision=?""",
            (suggestion["id"], revision),
        ).fetchone()
        if existing:
            if str(existing["review_record_sha256"]) != record_digest:
                raise ValueError(f"Review revision collision at {review_path}:{line_number}")
            continue
        latest_revision = int(
            connection.execute(
                """SELECT COALESCE(MAX(revision), 0) FROM classification_review
                   WHERE classification_suggestion_id=?""",
                (suggestion["id"],),
            ).fetchone()[0]
        )
        if revision != latest_revision + 1:
            raise ValueError(
                f"Review revision must follow {latest_revision} at {review_path}:{line_number}"
            )
        connection.execute(
            """INSERT INTO classification_review
               (classification_suggestion_id, revision, decision,
                replacement_taxonomy_term_id, replacement_relationship,
                replacement_effect_direction, replacement_directness, reviewer,
                reviewed_at, notes, review_record_sha256, review_file_sha256)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                suggestion["id"],
                revision,
                decision,
                replacement_term_id,
                replacement_relationship,
                replacement_direction,
                replacement_directness,
                reviewer,
                reviewed_at,
                review.get("notes"),
                record_digest,
                digest,
            ),
        )
        applied += 1
    return applied


def rebuild_vote_search(connection: sqlite3.Connection) -> int:
    """Rebuild the exact-identifier and full-text vote search projection."""

    connection.execute("DELETE FROM voting_event_search_fts")
    connection.execute("DELETE FROM voting_event_search_document")
    query = """
    SELECT
      event.id,
      event.source_identifier AS voting_identifier,
      event.registration_number,
      substr(event.occurred_at, 1, 10) AS occurred_on,
      event.division_text,
      event.submission_text,
      event.meaning_yes,
      event.meaning_no,
      matter.id AS matter_id,
      matter.formatted_identifier AS affair_identifier,
      matter.source_identifier AS affair_source_identifier,
      matter.title,
      (SELECT group_concat(topic.name, ' | ')
         FROM matter_topic link JOIN official_topic topic ON topic.id=link.topic_id
        WHERE link.matter_id=matter.id) AS topics,
      (SELECT group_concat(descriptor.name, ' | ')
         FROM matter_descriptor link JOIN official_descriptor descriptor ON descriptor.id=link.descriptor_id
        WHERE link.matter_id=matter.id) AS descriptors,
      (SELECT group_concat(body_html, ' | ') FROM matter_text WHERE matter_id=matter.id) AS matter_texts,
      (SELECT group_concat(
          coalesce(description_html,'') || ' ' || coalesce(initial_situation_html,'') || ' ' || coalesce(proceedings_html,''),
          ' | '
       ) FROM matter_summary WHERE matter_id=matter.id) AS summaries,
      (SELECT group_concat(term.name_de, ' | ')
         FROM reviewed_classification reviewed
         JOIN taxonomy_term term ON term.id=reviewed.taxonomy_term_id
        WHERE reviewed.voting_event_id=event.id OR reviewed.matter_id=matter.id) AS reviewed_labels
    FROM voting_event event
    LEFT JOIN parliamentary_matter matter ON matter.id=event.matter_id
    ORDER BY event.id
    """
    count = 0
    for row in connection.execute(query):
        official_metadata = " | ".join(
            str(value) for value in (row["topics"], row["descriptors"]) if value not in (None, "")
        )
        exact_question = " | ".join(
            str(value)
            for value in (row["division_text"], row["submission_text"])
            if value not in (None, "")
        )
        reviewed_labels = str(row["reviewed_labels"] or "")
        full_text = " | ".join(
            normalized_text(value)
            for value in (
                row["affair_identifier"],
                row["affair_source_identifier"],
                row["voting_identifier"],
                row["registration_number"],
                row["occurred_on"],
                row["title"],
                exact_question,
                row["meaning_yes"],
                row["meaning_no"],
                official_metadata,
                row["matter_texts"],
                row["summaries"],
                reviewed_labels,
            )
            if value not in (None, "")
        )
        connection.execute(
            """INSERT INTO voting_event_search_document
               (voting_event_id, affair_identifier, affair_source_identifier, voting_identifier,
                registration_number, occurred_on, title, exact_question,
                meaning_yes, meaning_no, official_metadata,
                reviewed_classifications, full_text)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                row["id"],
                row["affair_identifier"],
                row["affair_source_identifier"],
                row["voting_identifier"],
                row["registration_number"],
                row["occurred_on"],
                row["title"],
                exact_question,
                row["meaning_yes"],
                row["meaning_no"],
                official_metadata,
                reviewed_labels,
                full_text,
            ),
        )
        connection.execute(
            "INSERT INTO voting_event_search_fts(voting_event_id, full_text) VALUES (?, ?)",
            (row["id"], full_text),
        )
        count += 1
    return count


def search_votes(connection: sqlite3.Connection, query: str, limit: int = 20) -> list[dict[str, Any]]:
    """Find exact identifiers first, then bounded full-text results."""

    query = query.strip()
    if not query or limit < 1 or limit > 100:
        raise ValueError("Search query is required and limit must be between 1 and 100")
    exact = connection.execute(
        """SELECT document.*, 'exact' AS match_type
           FROM voting_event_search_document document
           WHERE affair_identifier=? OR affair_source_identifier=? OR voting_identifier=?
              OR registration_number=? OR occurred_on=?
           ORDER BY occurred_on DESC, voting_event_id LIMIT ?""",
        (query, query, query, query, query, limit),
    ).fetchall()
    if exact:
        return [dict(row) for row in exact]
    tokens = re.findall(r"[\w]+", normalized_text(query), flags=re.UNICODE)
    if not tokens:
        return []
    fts_query = " AND ".join(f'"{token}"*' for token in tokens)
    rows = connection.execute(
        """SELECT document.*, 'full_text' AS match_type,
                  snippet(voting_event_search_fts, 1, '[', ']', ' … ', 24) AS match_context
           FROM voting_event_search_fts
           JOIN voting_event_search_document document
             ON document.voting_event_id=voting_event_search_fts.voting_event_id
           WHERE voting_event_search_fts MATCH ?
           ORDER BY bm25(voting_event_search_fts), document.occurred_on DESC
           LIMIT ?""",
        (fts_query, limit),
    ).fetchall()
    return [dict(row) for row in rows]

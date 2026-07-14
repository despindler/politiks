from __future__ import annotations

import sqlite3
from pathlib import Path

from politiks.importer import _normal_choice, recreate_database


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]


def test_source_decision_tokens_are_preserved_through_a_small_explicit_mapping() -> None:
    assert _normal_choice("Yes") == "yes"
    assert _normal_choice("No") == "no"
    assert _normal_choice("EH") == "abstain"
    assert _normal_choice("ES") == "not_participating"
    assert _normal_choice("NT") == "not_participating"
    assert _normal_choice("P") == "presiding"
    assert _normal_choice("unexpected") == "other"


def test_fixture_import_is_idempotent_and_referentially_sound(tmp_path: Path) -> None:
    database_path = tmp_path / "parliament.sqlite"
    first = recreate_database(REPOSITORY_ROOT, database_path=database_path)
    second = recreate_database(REPOSITORY_ROOT, database_path=database_path)

    assert first.table_counts == second.table_counts
    assert first.choice_counts == second.choice_counts
    assert first.source_files == 38
    assert first.source_records == 960
    assert first.normalized_files == 31
    assert first.foreign_key_violations == 0
    assert first.unlinked_event_matters == 0
    assert first.table_counts["voting_event"] == 54
    assert first.table_counts["voting_choice"] == 2241

    connection = sqlite3.connect(database_path)
    try:
        duplicate_identifiers = connection.execute(
            """SELECT COUNT(*) FROM (
                   SELECT source_system, namespace, identifier
                   FROM person_identifier
                   GROUP BY source_system, namespace, identifier HAVING COUNT(*) > 1
               )"""
        ).fetchone()[0]
        assert duplicate_identifiers == 0
        assert connection.execute("PRAGMA foreign_key_check").fetchall() == []
    finally:
        connection.close()


def test_identifier_namespaces_and_dated_membership_join_remain_auditable(
    tmp_path: Path,
) -> None:
    database_path = tmp_path / "parliament.sqlite"
    recreate_database(REPOSITORY_ROOT, database_path=database_path)
    connection = sqlite3.connect(database_path)
    connection.row_factory = sqlite3.Row
    try:
        identifiers = connection.execute(
            """SELECT namespace, identifier FROM person_identifier pi
               JOIN person p ON p.id = pi.person_id
               WHERE p.display_name = 'Marianne Binder-Keller'
               ORDER BY namespace"""
        ).fetchall()
        assert {(row["namespace"], row["identifier"]) for row in identifiers} >= {
            ("cv_person_id", "4249"),
            ("voting_councillor_number", "3141"),
            ("elan_id", "921"),
        }

        joined = connection.execute(
            """SELECT p.display_name, ve.occurred_at, pm.title,
                      vc.normalized_choice, pp.abbreviation AS party,
                      ppm.is_inferred, ppm.evidence_basis
               FROM voting_choice vc
               JOIN person p ON p.id = vc.person_id
               JOIN voting_event ve ON ve.id = vc.voting_event_id
               JOIN parliamentary_matter pm ON pm.id = ve.matter_id
               JOIN person_party_membership ppm ON ppm.person_id = p.id
                 AND (ppm.date_from IS NULL OR ppm.date_from <= substr(ve.occurred_at, 1, 10))
                 AND (ppm.date_to IS NULL OR ppm.date_to >= substr(ve.occurred_at, 1, 10))
               JOIN political_party pp ON pp.id = ppm.party_id
               WHERE p.display_name = 'Thomas Aeschi'
               ORDER BY ve.occurred_at DESC LIMIT 1"""
        ).fetchone()
        assert joined is not None
        assert joined["party"] == "SVP"
        assert joined["is_inferred"] == 1
        assert joined["evidence_basis"] == "inferred_current_profile_party_over_mandate"
    finally:
        connection.close()

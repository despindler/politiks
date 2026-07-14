from __future__ import annotations

from pathlib import Path

from politiks.swiss_xlsx import (
    normalized_aggregate_label,
    parse_workbook,
)


SNAPSHOT = Path("source/snapshots/swiss_2026-07-14/votes/session-spreadsheets")


def test_descriptive_aggregate_labels_are_normalized() -> None:
    assert normalized_aggregate_label("Anzahl 'Ja'") == "yes"
    assert normalized_aggregate_label("Anzahl Nein") == "no"
    assert normalized_aggregate_label("Anzahl Enthaltungen") == "abstain"
    assert normalized_aggregate_label("Anzahl nicht teilgenommen") == "not_participating"
    assert normalized_aggregate_label("Teilnahme Präsident/in an der Abstimmung") == "presiding"
    assert normalized_aggregate_label("Unrelated metadata") is None


def test_legacy_workbook_preserves_vote_and_member_totals() -> None:
    path = SNAPSHOT / "nr" / "4901-2011-wintersession-d.xlsx"
    event = next(parse_workbook(path, "NR"))

    assert event["layout"] == "legacy"
    assert event["registration_number"] == "6514"
    assert event["affair_id"] == "20070057"
    assert event["aggregates"] == {
        "yes": 114,
        "no": 42,
        "excused": 0,
        "not_participating": 43,
    }
    assert len(event["choices"]) == 200


def test_current_workbook_does_not_create_other_aggregate() -> None:
    path = SNAPSHOT / "nr" / "Abstimmungen_NR_2026SS_DE.xlsx"
    event = next(parse_workbook(path, "NR"))

    assert event["layout"] == "current"
    assert event["registration_number"] == "32381"
    assert event["aggregates"] == {
        "yes": 175,
        "no": 4,
        "abstain": 1,
        "excused": 3,
        "not_participating": 16,
    }
    assert len(event["choices"]) == 200


def test_council_of_states_transitional_workbook_is_supported() -> None:
    path = SNAPSHOT / "sr" / "Abstimmungen_SR_2022FS_DE.xlsx"
    event = next(parse_workbook(path, "SR"))

    assert event["layout"] == "transitional"
    assert event["registration_number"] == "4942"
    assert event["aggregates"] == {
        "yes": 24,
        "no": 18,
        "abstain": 0,
        "excused": 1,
        "not_participating": 2,
    }
    assert len(event["choices"]) == 46

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path

from politiks.acquisition import validate_snapshot

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "source"
SNAPSHOT = SOURCE / "snapshots" / "fixture_2026-07-14"
MANIFEST = SOURCE / "manifests" / "fixture.jsonl"


def load_json(relative_path: str):
    return json.loads((SNAPSHOT / relative_path).read_text(encoding="utf-8"))


def test_official_fixture_matches_manifest() -> None:
    result = validate_snapshot(SOURCE, MANIFEST)

    assert result["files"] == 38
    assert result["unresolved_errors"] == 0
    assert result["bytes"] > 1_000_000


def test_fixture_spans_both_current_councils_in_member_metadata() -> None:
    members = load_json("councillors/basic-details.json")

    assert Counter(member["council"] for member in members) == {"N": 200, "S": 46}


def test_biography_and_voting_identifiers_remain_distinct() -> None:
    members = load_json("councillors/basic-details.json")
    binder = next(member for member in members if member["number"] == 3141)
    biography = load_json("councillors/detail/4249.json")
    voting = load_json("votes/councillors/detail/3141/page_000001.json")

    assert binder["id"] == 4249
    assert binder["council"] == "S"
    assert biography["id"] == 4249
    assert biography["lastName"] == "Binder-Keller"
    assert voting["id"] == 3141
    assert voting["elanId"] == 921
    assert voting["lastName"] == "Binder-Keller"


def test_sampled_vote_events_are_national_council_scale_without_chamber_field() -> None:
    details = [
        load_json("votes/affairs/detail/20120409.json"),
        load_json("votes/affairs/detail/20150320.json"),
        load_json("votes/affairs/detail/20130468.json"),
    ]
    events = [event for detail in details for event in detail["affairVotes"]]

    assert len(events) == 11
    assert {len(event["councillorVotes"]) for event in events} == {199, 200}
    assert all("council" not in event and "chamber" not in event for event in events)
    assert {choice["decision"] for event in events for choice in event["councillorVotes"]} == {
        "Yes",
        "No",
        "EH",
        "ES",
        "NT",
        "P",
    }
    final_votes = [
        event for event in events if "schlussabstimmung" in (event.get("divisionText") or "").lower()
    ]
    assert len(final_votes) == 1
    assert final_votes[0]["meaningYes"] == "Annahme der Vorlage"
    assert final_votes[0]["meaningNo"] == "Ablehnung der Vorlage"

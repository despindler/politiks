#!/usr/bin/env python3
"""Export a bounded, provenance-rich queue of pending review suggestions."""

from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Export pending classification suggestions as JSONL.")
    parser.add_argument("--database", type=Path, default=Path("database/parliament.sqlite"))
    parser.add_argument("--limit", type=int, default=100, help="1-1000 suggestions")
    parser.add_argument(
        "--dimension",
        choices=("policy_topic", "affected_group", "effect_mechanism"),
    )
    parser.add_argument("--output", type=Path, help="Optional output file; stdout by default")
    return parser.parse_args()


def repo_path(path: Path) -> Path:
    return path if path.is_absolute() else REPOSITORY_ROOT / path


def main() -> int:
    args = parse_arguments()
    if args.limit < 1 or args.limit > 1000:
        raise SystemExit("--limit must be between 1 and 1000")
    database = repo_path(args.database)
    connection = sqlite3.connect(database)
    connection.row_factory = sqlite3.Row
    try:
        parameters: list[object] = []
        dimension_filter = ""
        if args.dimension:
            dimension_filter = "AND term.dimension=?"
            parameters.append(args.dimension)
        parameters.append(args.limit)
        rows = connection.execute(
            f"""SELECT
                  suggestion.suggestion_key,
                  term.dimension,
                  term.code AS term,
                  term.name_de,
                  suggestion.relationship,
                  suggestion.effect_direction,
                  suggestion.directness,
                  suggestion.confidence,
                  suggestion.evidence_field,
                  suggestion.evidence_passage,
                  suggestion.rule_id,
                  event.source_identifier AS voting_identifier,
                  event.registration_number,
                  event.occurred_at,
                  coalesce(matter.formatted_identifier, matter.source_identifier) AS affair_identifier,
                  matter.title
                FROM classification_suggestion suggestion
                JOIN taxonomy_term term ON term.id=suggestion.taxonomy_term_id
                LEFT JOIN voting_event event ON event.id=suggestion.voting_event_id
                LEFT JOIN parliamentary_matter matter
                  ON matter.id=coalesce(suggestion.matter_id, event.matter_id)
                WHERE NOT EXISTS (
                  SELECT 1 FROM classification_review review
                  WHERE review.classification_suggestion_id=suggestion.id
                )
                {dimension_filter}
                ORDER BY suggestion.confidence DESC, event.occurred_at DESC,
                         suggestion.suggestion_key
                LIMIT ?""",
            parameters,
        ).fetchall()
        output = "".join(
            json.dumps(dict(row), ensure_ascii=False, sort_keys=True) + "\n" for row in rows
        )
        if args.output:
            destination = repo_path(args.output)
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(output, encoding="utf-8")
            print(json.dumps({"output": str(destination), "suggestions": len(rows)}))
        else:
            sys.stdout.write(output)
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    sys.exit(main())

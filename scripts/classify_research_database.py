#!/usr/bin/env python3
"""Run deterministic suggestions, controlled reviews, and vote search offline."""

from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPOSITORY_ROOT / "src"))

from politiks.classification import (  # noqa: E402
    apply_review_file,
    rebuild_vote_search,
    run_deterministic_classification,
)


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Create auditable pending classifications and rebuild vote search."
    )
    parser.add_argument(
        "--database",
        type=Path,
        default=Path("database/parliament.sqlite"),
        help="Existing generated research database",
    )
    parser.add_argument(
        "--taxonomy",
        type=Path,
        default=Path("classification/taxonomy/v1.de.json"),
    )
    parser.add_argument(
        "--rules",
        type=Path,
        default=Path("classification/rules/v1.de.json"),
    )
    parser.add_argument(
        "--reviews",
        type=Path,
        default=Path("classification/reviews/v1.jsonl"),
    )
    return parser.parse_args()


def repository_path(path: Path) -> Path:
    return path if path.is_absolute() else REPOSITORY_ROOT / path


def main() -> int:
    args = parse_arguments()
    database = repository_path(args.database)
    if not database.is_file():
        raise SystemExit(f"Research database not found: {database}")
    connection = sqlite3.connect(database)
    connection.row_factory = sqlite3.Row
    try:
        connection.execute("PRAGMA foreign_keys = ON")
        with connection:
            report = run_deterministic_classification(
                connection,
                repository_path(args.taxonomy),
                repository_path(args.rules),
            )
            applied_reviews = apply_review_file(connection, repository_path(args.reviews))
            search_documents = rebuild_vote_search(connection)
        counts = {
            "pending": int(
                connection.execute(
                    """SELECT COUNT(*) FROM classification_suggestion suggestion
                       WHERE NOT EXISTS (
                         SELECT 1 FROM classification_review review
                         WHERE review.classification_suggestion_id=suggestion.id
                       )"""
                ).fetchone()[0]
            ),
            "reviewed": int(connection.execute("SELECT COUNT(*) FROM reviewed_classification").fetchone()[0]),
        }
        print(
            json.dumps(
                {
                    "run_key": report.run_key,
                    "targets": report.targets,
                    "suggestions": report.suggestions,
                    "suggestions_by_dimension": report.by_dimension,
                    "reviews_applied": applied_reviews,
                    "review_state": counts,
                    "search_documents": search_documents,
                },
                ensure_ascii=False,
                indent=2,
            )
        )
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    sys.exit(main())

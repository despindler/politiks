#!/usr/bin/env python3
"""Evaluate transparent deterministic rules against the labeled benchmark."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPOSITORY_ROOT / "src"))

from politiks.classification import classify_benchmark_text  # noqa: E402


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Evaluate classification benchmark cases.")
    parser.add_argument(
        "--benchmark", type=Path, default=Path("classification/benchmark/v1.de.json")
    )
    parser.add_argument(
        "--taxonomy", type=Path, default=Path("classification/taxonomy/v1.de.json")
    )
    parser.add_argument("--rules", type=Path, default=Path("classification/rules/v1.de.json"))
    return parser.parse_args()


def repo_path(path: Path) -> Path:
    return path if path.is_absolute() else REPOSITORY_ROOT / path


def main() -> int:
    args = parse_arguments()
    benchmark = json.loads(repo_path(args.benchmark).read_text(encoding="utf-8"))
    failures = []
    results = []
    for case in benchmark.get("cases") or []:
        suggestions = classify_benchmark_text(
            repo_path(args.taxonomy), repo_path(args.rules), str(case["text"])
        )
        rule_ids = {suggestion.rule_id for suggestion in suggestions}
        relationships = {suggestion.relationship for suggestion in suggestions}
        missing = sorted(set(case.get("required_rule_ids") or []) - rule_ids)
        forbidden = sorted(set(case.get("forbidden_relationships") or []) & relationships)
        passed = not missing and not forbidden
        result = {
            "id": case["id"],
            "kind": case["kind"],
            "passed": passed,
            "suggestions": len(suggestions),
            "missing_rules": missing,
            "forbidden_relationships": forbidden,
        }
        results.append(result)
        if not passed:
            failures.append(result)
    print(
        json.dumps(
            {"cases": len(results), "passed": len(results) - len(failures), "failed": len(failures), "results": results},
            ensure_ascii=False,
            indent=2,
        )
    )
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())

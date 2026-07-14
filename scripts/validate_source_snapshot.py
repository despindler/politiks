#!/usr/bin/env python3
"""Validate preserved source files against their JSONL acquisition manifest."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"
if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from politiks.acquisition import AcquisitionError, validate_snapshot  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, default=ROOT / "source")
    parser.add_argument(
        "--manifest",
        type=Path,
        default=ROOT / "source" / "manifests" / "fixture.jsonl",
    )
    args = parser.parse_args()

    try:
        result = validate_snapshot(args.source_root, args.manifest)
    except AcquisitionError as error:
        print(f"Snapshot validation failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

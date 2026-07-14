#!/usr/bin/env python3
"""Download a declared source snapshot into source/ with an auditable manifest."""

from __future__ import annotations

import argparse
import json
import logging
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"
if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))

from politiks.acquisition import AcquisitionError, DownloadSettings, run_plan  # noqa: E402


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, default=ROOT / "source" / "plans" / "fixture.json")
    parser.add_argument("--source-root", type=Path, default=ROOT / "source")
    parser.add_argument(
        "--manifest",
        type=Path,
        default=ROOT / "source" / "manifests" / "fixture.jsonl",
    )
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--minimum-interval", type=float, default=0.25)
    parser.add_argument("--max-retries", type=int, default=3)
    parser.add_argument("--backoff", type=float, default=0.5)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    settings = DownloadSettings(
        source_root=args.source_root,
        manifest_path=args.manifest,
        timeout_seconds=args.timeout,
        minimum_interval_seconds=args.minimum_interval,
        max_retries=args.max_retries,
        backoff_seconds=args.backoff,
    )
    try:
        summary = run_plan(args.plan, settings)
    except AcquisitionError as error:
        logging.error("Acquisition stopped safely: %s", error)
        return 1

    print(json.dumps(summary.as_dict(), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

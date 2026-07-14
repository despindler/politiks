#!/usr/bin/env python3
"""Generate a dated plan for all official Swiss session vote spreadsheets."""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
import time
from pathlib import Path
from urllib.parse import unquote, urljoin, urlsplit

import requests


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
SOURCE_PAGE = "https://www.parlament.ch/de/ratsbetrieb/abstimmungen/abstimmung-nr-xls"
USER_AGENT = "Politiks-research/0.1 (+https://github.com/despindler/politiks)"


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate an immutable full Swiss vote-spreadsheet acquisition plan."
    )
    parser.add_argument("--snapshot", required=True, help="Snapshot directory name, e.g. swiss_2026-07-14")
    parser.add_argument("--output", type=Path, required=True, help="Destination JSON plan")
    return parser.parse_args()


def workbook_links(page_html: str) -> list[dict[str, str]]:
    """Extract unique official NR/SR XLSX links in their page order."""

    links: list[dict[str, str]] = []
    for href, body in re.findall(r'(?is)<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>', page_html):
        label = " ".join(html.unescape(re.sub(r"<[^>]+>", " ", body)).split())
        if "XLSX" not in label.upper() or not re.search(r"\b(?:NR|SR)\b", label):
            continue
        chamber_match = re.search(r"\b(NR|SR)\b", label)
        if chamber_match is None:
            continue
        url = urljoin(SOURCE_PAGE, html.unescape(href))
        if url not in [item["url"] for item in links]:
            links.append({"chamber": chamber_match.group(1), "url": url})
    return links


def resolve_workbooks(
    session: requests.Session, links: list[dict[str, str]]
) -> list[dict[str, str]]:
    workbooks: list[dict[str, str]] = []
    seen: set[str] = set()
    for link in links:
        response = session.head(link["url"], allow_redirects=True, timeout=30)
        response.raise_for_status()
        final_url = response.url
        if final_url in seen:
            continue
        seen.add(final_url)
        filename = unquote(Path(urlsplit(final_url).path).name)
        if not filename.lower().endswith(".xlsx"):
            raise RuntimeError(f"Official session link did not resolve to XLSX: {final_url}")
        chamber = link["chamber"]
        workbooks.append({"chamber": chamber, "filename": filename, "url": final_url})
        time.sleep(0.05)
    return workbooks


def reference_items(snapshot: str) -> list[dict[str, object]]:
    root = f"snapshots/{snapshot}"
    return [
        {
            "name": "official_session_spreadsheet_page",
            "url": SOURCE_PAGE,
            "path": f"{root}/documentation/session-spreadsheets.html",
            "format": "binary",
        },
        {
            "name": "official_voting_data_access_rules",
            "url": "https://www.parlament.ch/centers/documents/de/Informationen%20Zugang%20Abstimmungsdaten_DE.pdf",
            "path": f"{root}/documentation/voting-data-access-rules.pdf",
            "format": "pdf",
        },
        {
            "name": "councils",
            "url": "http://ws-old.parlament.ch/councils",
            "params": {"lang": "de"},
            "path": f"{root}/reference/councils.json",
        },
        {
            "name": "legislative_periods",
            "url": "http://ws-old.parlament.ch/LegislativePeriods",
            "params": {"lang": "de"},
            "path": f"{root}/reference/legislative-periods.json",
        },
        {
            "name": "sessions_all",
            "url": "http://ws-old.parlament.ch/sessions",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/reference/sessions/page_{{page:06d}}.json",
        },
        {
            "name": "cantons",
            "url": "http://ws-old.parlament.ch/cantons",
            "params": {"lang": "de"},
            "path": f"{root}/reference/cantons.json",
        },
        {
            "name": "committees_all",
            "url": "http://ws-old.parlament.ch/committees",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/reference/committees/page_{{page:06d}}.json",
        },
        {
            "name": "factions_current",
            "url": "http://ws-old.parlament.ch/Factions",
            "params": {"lang": "de"},
            "path": f"{root}/reference/factions/current.json",
        },
        {
            "name": "factions_historic_all",
            "url": "http://ws-old.parlament.ch/Factions/historic",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/reference/factions/historic/page_{{page:06d}}.json",
        },
        {
            "name": "parties_historic_all",
            "url": "http://ws-old.parlament.ch/Parties/historic",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/reference/parties/historic/page_{{page:06d}}.json",
        },
        {
            "name": "councillors_basic_details",
            "url": "http://ws-old.parlament.ch/councillors/basicdetails",
            "params": {"lang": "de"},
            "path": f"{root}/councillors/basic-details.json",
        },
        {
            "name": "councillors_all",
            "url": "http://ws-old.parlament.ch/councillors",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/councillors/list/page_{{page:06d}}.json",
        },
        {
            "name": "councillors_historic_all",
            "url": "http://ws-old.parlament.ch/councillors/historic",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/councillors/historic/page_{{page:06d}}.json",
        },
        {
            "name": "vote_councillors_all",
            "url": "http://ws-old.parlament.ch/votes/councillors",
            "params": {"lang": "de"},
            "mode": "paginated",
            "path_template": f"{root}/votes/councillors/page_{{page:06d}}.json",
        },
        {
            "name": "affair_types",
            "url": "http://ws-old.parlament.ch/affairs/types",
            "params": {"lang": "de"},
            "path": f"{root}/affairs/reference/types.json",
        },
        {
            "name": "affair_states",
            "url": "http://ws-old.parlament.ch/affairs/states",
            "params": {"lang": "de"},
            "path": f"{root}/affairs/reference/states.json",
        },
        {
            "name": "affair_topics",
            "url": "http://ws-old.parlament.ch/affairs/topics",
            "params": {"lang": "de"},
            "path": f"{root}/affairs/reference/topics.json",
        },
        {
            "name": "affair_descriptors",
            "url": "http://ws-old.parlament.ch/affairs/descriptors",
            "params": {"lang": "de"},
            "path": f"{root}/affairs/reference/descriptors.json",
        },
    ]


def main() -> int:
    args = parse_arguments()
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]+", args.snapshot):
        raise SystemExit("--snapshot must contain only lowercase letters, digits, underscores, and hyphens")

    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT})
    page = session.get(SOURCE_PAGE, timeout=30)
    page.raise_for_status()
    workbooks = resolve_workbooks(session, workbook_links(page.text))
    chamber_counts = {
        chamber: sum(item["chamber"] == chamber for item in workbooks) for chamber in ("NR", "SR")
    }
    if chamber_counts["NR"] < 70 or chamber_counts["SR"] < 19:
        raise RuntimeError(f"Official page exposed an unexpectedly small workbook set: {chamber_counts}")

    items = reference_items(args.snapshot)
    for workbook in workbooks:
        filename = workbook["filename"]
        safe_filename = re.sub(r"[^A-Za-z0-9._-]+", "_", filename)
        items.append(
            {
                "name": f"session_votes_{Path(safe_filename).stem.lower()}",
                "url": workbook["url"],
                "path": f"snapshots/{args.snapshot}/votes/session-spreadsheets/{workbook['chamber'].lower()}/{safe_filename}",
                "format": "xlsx",
                "notes": f"Official chamber-explicit {workbook['chamber']} session spreadsheet.",
            }
        )

    plan = {"version": 1, "snapshot": args.snapshot, "items": items}
    output = args.output if args.output.is_absolute() else REPOSITORY_ROOT / args.output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(output), "items": len(items), **chamber_counts}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())

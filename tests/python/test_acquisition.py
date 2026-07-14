from __future__ import annotations

import hashlib
import io
import json
import threading
import zipfile
from collections import Counter
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

import pytest

from politiks.acquisition import (
    AcquisitionError,
    DownloadSettings,
    run_plan,
    validate_content,
    validate_snapshot,
)


@pytest.fixture()
def source_server():
    counters: Counter[str] = Counter()

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802
            parsed = urlsplit(self.path)
            counters[self.path] += 1

            if parsed.path == "/single":
                self.respond_json({"id": 1, "title": "Fixture"})
                return
            if parsed.path == "/pages":
                page = int(parse_qs(parsed.query).get("pageNumber", ["1"])[0])
                if page == 1:
                    self.respond_json([{"id": 1}, {"id": 2, "hasMorePages": True}])
                elif page == 2:
                    self.respond_json([{"id": 3, "hasMorePages": False}])
                else:
                    self.send_error(404)
                return
            if parsed.path == "/pages-with-404-end":
                page = int(parse_qs(parsed.query).get("pageNumber", ["1"])[0])
                if page == 1:
                    self.respond_json([{"id": value} for value in range(50)])
                else:
                    self.send_error(404)
                return
            if parsed.path == "/transient":
                if counters[self.path] == 1:
                    self.send_response(503)
                    self.send_header("Content-Type", "application/json")
                    self.end_headers()
                    self.wfile.write(b'{"error":"temporary"}')
                else:
                    self.respond_json({"ok": True})
                return
            if parsed.path == "/malformed":
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b"not-json")
                return
            self.send_error(404)

        def respond_json(self, value: object) -> None:
            payload = json.dumps(value).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def log_message(self, format: str, *args: object) -> None:
            return

    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}", counters
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()


def write_plan(path: Path, items: list[dict[str, object]]) -> None:
    path.write_text(json.dumps({"version": 1, "items": items}), encoding="utf-8")


def settings(tmp_path: Path) -> DownloadSettings:
    return DownloadSettings(
        source_root=tmp_path / "source",
        manifest_path=tmp_path / "source" / "manifests" / "test.jsonl",
        minimum_interval_seconds=0,
        backoff_seconds=0,
    )


def read_manifest(path: Path) -> list[dict[str, object]]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


def minimal_xlsx() -> bytes:
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w") as workbook:
        workbook.writestr("[Content_Types].xml", "<Types />")
        workbook.writestr(
            "xl/workbook.xml",
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" />',
        )
        workbook.writestr(
            "xl/worksheets/sheet1.xml",
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" />',
        )
    return output.getvalue()


def test_xlsx_validation_checks_the_workbook_container() -> None:
    assert validate_content(minimal_xlsx(), "xlsx") is None
    with pytest.raises(AcquisitionError, match="valid XLSX"):
        validate_content(b"not-a-workbook", "xlsx")


def test_download_is_checksummed_and_idempotent(tmp_path: Path, source_server) -> None:
    base_url, counters = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [{"name": "single", "url": f"{base_url}/single", "path": "fixture/single.json"}],
    )

    first = run_plan(plan_path, settings(tmp_path))
    second = run_plan(plan_path, settings(tmp_path))

    source_file = tmp_path / "source" / "fixture" / "single.json"
    records = read_manifest(tmp_path / "source" / "manifests" / "test.jsonl")
    assert first.as_dict() == {"downloaded": 1, "skipped": 0, "pagination_ends": 0, "failures": 0}
    assert second.as_dict() == {"downloaded": 0, "skipped": 1, "pagination_ends": 0, "failures": 0}
    assert counters["/single"] == 1
    assert len(records) == 1
    assert records[0]["sha256"] == hashlib.sha256(source_file.read_bytes()).hexdigest()
    assert records[0]["response_bytes"] == source_file.stat().st_size
    assert validate_snapshot(
        tmp_path / "source", tmp_path / "source" / "manifests" / "test.jsonl"
    ) == {"files": 1, "bytes": source_file.stat().st_size, "unresolved_errors": 0}


def test_existing_path_cannot_be_reused_for_another_request(tmp_path: Path, source_server) -> None:
    base_url, _ = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [{"name": "single", "url": f"{base_url}/single", "path": "fixture/single.json"}],
    )
    run_plan(plan_path, settings(tmp_path))

    write_plan(
        plan_path,
        [{"name": "changed", "url": f"{base_url}/changed", "path": "fixture/single.json"}],
    )

    with pytest.raises(AcquisitionError, match="different source request"):
        run_plan(plan_path, settings(tmp_path))


def test_relative_source_root_is_supported(tmp_path: Path, source_server, monkeypatch) -> None:
    base_url, _ = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [{"name": "single", "url": f"{base_url}/single", "path": "fixture/single.json"}],
    )
    monkeypatch.chdir(tmp_path)
    relative_settings = DownloadSettings(
        source_root=Path("source"),
        manifest_path=Path("source/manifests/test.jsonl"),
        minimum_interval_seconds=0,
    )

    summary = run_plan(plan_path, relative_settings)

    assert summary.downloaded == 1
    assert (tmp_path / "source" / "fixture" / "single.json").is_file()


def test_paginated_download_stops_on_source_flag(tmp_path: Path, source_server) -> None:
    base_url, counters = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [
            {
                "name": "pages",
                "url": f"{base_url}/pages",
                "mode": "paginated",
                "path_template": "fixture/page_{page:06d}.json",
            }
        ],
    )

    summary = run_plan(plan_path, settings(tmp_path))

    assert summary.downloaded == 2
    assert counters["/pages?pageNumber=1"] == 1
    assert counters["/pages?pageNumber=2"] == 1
    assert counters["/pages?pageNumber=3"] == 0


def test_paginated_download_treats_post_success_404_as_end(tmp_path: Path, source_server) -> None:
    base_url, counters = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [
            {
                "name": "pages-with-404-end",
                "url": f"{base_url}/pages-with-404-end",
                "mode": "paginated",
                "path_template": "fixture/404_page_{page:06d}.json",
            }
        ],
    )

    summary = run_plan(plan_path, settings(tmp_path))

    assert summary.downloaded == 1
    assert summary.pagination_ends == 1
    assert counters["/pages-with-404-end?pageNumber=1"] == 1
    assert counters["/pages-with-404-end?pageNumber=2"] == 1


def test_transient_failure_retries_and_records_count(tmp_path: Path, source_server) -> None:
    base_url, counters = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [{"name": "transient", "url": f"{base_url}/transient", "path": "fixture/transient.json"}],
    )

    summary = run_plan(plan_path, settings(tmp_path))
    record = read_manifest(tmp_path / "source" / "manifests" / "test.jsonl")[0]

    assert summary.downloaded == 1
    assert counters["/transient"] == 2
    assert record["retry_count"] == 1


def test_malformed_json_is_recorded_as_error_but_not_preserved(tmp_path: Path, source_server) -> None:
    base_url, _ = source_server
    plan_path = tmp_path / "plan.json"
    write_plan(
        plan_path,
        [{"name": "malformed", "url": f"{base_url}/malformed", "path": "fixture/malformed.json"}],
    )

    with pytest.raises(AcquisitionError, match="not valid UTF-8 JSON"):
        run_plan(plan_path, settings(tmp_path))

    assert not (tmp_path / "source" / "fixture" / "malformed.json").exists()
    records = read_manifest(tmp_path / "source" / "manifests" / "test.jsonl")
    assert len(records) == 1
    assert records[0]["state"] == "error"
    assert records[0]["http_status"] == 200
    with pytest.raises(AcquisitionError, match="1 unresolved acquisition errors"):
        validate_snapshot(
            tmp_path / "source", tmp_path / "source" / "manifests" / "test.jsonl"
        )

"""Auditable, resumable acquisition of official source snapshots."""

from __future__ import annotations

import hashlib
import io
import json
import logging
import os
import time
import xml.etree.ElementTree as ET
import zipfile
from dataclasses import dataclass
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

import requests

ATTRIBUTION = "Parlamentsdienste der Bundesversammlung, Bern"
DEFAULT_USER_AGENT = "Politiks-research/0.1 (+https://github.com/despindler/politiks)"
TRANSIENT_STATUS_CODES = {408, 425, 429, 500, 502, 503, 504}
SENSITIVE_QUERY_PARTS = ("password", "secret", "token", "credential", "api_key", "apikey")


class AcquisitionError(RuntimeError):
    """Raised when a source response cannot be preserved safely."""


@dataclass(frozen=True)
class DownloadSettings:
    source_root: Path
    manifest_path: Path
    timeout_seconds: float = 30.0
    minimum_interval_seconds: float = 0.25
    max_retries: int = 3
    backoff_seconds: float = 0.5
    user_agent: str = DEFAULT_USER_AGENT


@dataclass
class DownloadSummary:
    downloaded: int = 0
    skipped: int = 0
    pagination_ends: int = 0
    failures: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "downloaded": self.downloaded,
            "skipped": self.skipped,
            "pagination_ends": self.pagination_ends,
            "failures": self.failures,
        }


class ManifestStore:
    """Append-only JSONL manifest with one success record per local snapshot file."""

    def __init__(self, path: Path) -> None:
        self.path = path
        self.records: list[dict[str, Any]] = []
        self.success_by_path: dict[str, dict[str, Any]] = {}
        if path.exists():
            self._load()

    def _load(self) -> None:
        with self.path.open("r", encoding="utf-8") as handle:
            for line_number, line in enumerate(handle, start=1):
                if not line.strip():
                    continue
                try:
                    record = json.loads(line)
                except json.JSONDecodeError as error:
                    raise AcquisitionError(
                        f"Invalid manifest JSON at {self.path}:{line_number}: {error}"
                    ) from error
                local_path = record.get("local_path")
                if not isinstance(local_path, str) or not local_path:
                    raise AcquisitionError(
                        f"Manifest record at {self.path}:{line_number} has no local_path."
                    )
                self.records.append(record)
                if record.get("state") == "success":
                    if local_path in self.success_by_path:
                        raise AcquisitionError(f"Duplicate successful manifest path: {local_path}")
                    self.success_by_path[local_path] = record

    def get(self, local_path: str) -> dict[str, Any] | None:
        return self.success_by_path.get(local_path)

    def append(self, record: dict[str, Any]) -> None:
        local_path = str(record["local_path"])
        if record.get("state") == "success" and local_path in self.success_by_path:
            raise AcquisitionError(f"Manifest already contains a success for {local_path}.")
        self.path.parent.mkdir(parents=True, exist_ok=True)
        serialized = json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        with self.path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(serialized + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        self.records.append(record)
        if record.get("state") == "success":
            self.success_by_path[local_path] = record


class SourceDownloader:
    def __init__(self, settings: DownloadSettings, logger: logging.Logger | None = None) -> None:
        self.settings = settings
        self.logger = logger or logging.getLogger("politiks.acquisition")
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": settings.user_agent})
        self.manifest = ManifestStore(settings.manifest_path)
        self.last_request_started: float | None = None

    def close(self) -> None:
        self.session.close()

    def download_item(self, item: dict[str, Any], summary: DownloadSummary) -> None:
        mode = item.get("mode", "single")
        if mode == "single":
            result = self._download_request(item, item["path"], dict(item.get("params", {})))
            self._record_result(result, summary)
            return
        if mode == "paginated":
            self._download_paginated(item, summary)
            return
        raise AcquisitionError(f"Unsupported acquisition mode {mode!r} for {item.get('name')!r}.")

    def _download_paginated(self, item: dict[str, Any], summary: DownloadSummary) -> None:
        page_parameter = str(item.get("page_parameter", "pageNumber"))
        page = int(item.get("first_page", 1))
        max_pages = item.get("max_pages")
        downloaded_pages = 0

        while max_pages is None or downloaded_pages < int(max_pages):
            params = dict(item.get("params", {}))
            params[page_parameter] = page
            relative_path = str(item["path_template"]).format(page=page)
            result = self._download_request(
                item,
                relative_path,
                params,
                allow_pagination_404=downloaded_pages > 0,
            )
            if result["state"] == "pagination_end":
                summary.pagination_ends += 1
                break

            self._record_result(result, summary)
            downloaded_pages += 1

            data = result.get("validated_data")
            has_more = detect_has_more_pages(data)
            if has_more is False:
                break
            page += 1

    @staticmethod
    def _record_result(result: dict[str, Any], summary: DownloadSummary) -> None:
        if result["state"] == "downloaded":
            summary.downloaded += 1
        elif result["state"] == "skipped":
            summary.skipped += 1

    def _download_request(
        self,
        item: dict[str, Any],
        relative_path: str,
        params: dict[str, Any],
        *,
        allow_pagination_404: bool = False,
    ) -> dict[str, Any]:
        name = str(item["name"])
        url = str(item["url"])
        response_format = str(item.get("format", "json"))
        validate_source_request(url, params)
        target = resolve_target(self.settings.source_root, relative_path)
        manifest_path = target.relative_to(self.settings.source_root.resolve()).as_posix()

        if target.exists():
            return self._validate_existing(
                target,
                manifest_path,
                response_format,
                name,
                url,
                params,
            )

        if self.manifest.get(manifest_path) is not None:
            raise AcquisitionError(
                f"Manifest describes {manifest_path}, but the source file is missing."
            )

        headers = {"Accept": accept_header(response_format)}
        response: requests.Response | None = None
        retry_count: int | None = None
        try:
            response, retry_count = self._request_with_retries(url, params, headers, name)
            if response.status_code == 404 and allow_pagination_404:
                self.logger.info("%s reached the end of pagination.", name)
                return {"state": "pagination_end"}
            if not response.ok:
                raise AcquisitionError(f"{name} returned HTTP {response.status_code}.")

            content = response.content
            validated_data = validate_content(content, response_format)
            validate_content_type(response.headers.get("content-type", ""), response_format)
        except AcquisitionError as error:
            failure = {
                "attribution": ATTRIBUTION,
                "endpoint": name,
                "error": str(error),
                "http_status": response.status_code if response is not None else None,
                "local_path": manifest_path,
                "notes": item.get("notes", ""),
                "query_parameters": params,
                "requested_url": url,
                "response_bytes": len(response.content) if response is not None else 0,
                "response_content_type": response.headers.get("content-type", "") if response is not None else "",
                "retrieval_timestamp_utc": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
                "retry_count": retry_count,
                "sha256": hashlib.sha256(response.content).hexdigest() if response is not None else None,
                "source_url": response.url if response is not None else url,
                "state": "error",
            }
            self.manifest.append(failure)
            raise

        checksum = hashlib.sha256(content).hexdigest()

        target.parent.mkdir(parents=True, exist_ok=True)
        partial = target.with_name(target.name + ".part")
        if partial.exists():
            partial.unlink()
        try:
            with partial.open("xb") as handle:
                handle.write(content)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(partial, target)
        finally:
            if partial.exists():
                partial.unlink()

        record = {
            "attribution": ATTRIBUTION,
            "endpoint": name,
            "http_status": response.status_code,
            "local_path": manifest_path,
            "notes": item.get("notes", ""),
            "query_parameters": params,
            "requested_url": url,
            "response_bytes": len(content),
            "response_content_type": response.headers.get("content-type", ""),
            "retrieval_timestamp_utc": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            "retry_count": retry_count,
            "sha256": checksum,
            "source_url": response.url,
            "state": "success",
        }
        self.manifest.append(record)
        self.logger.info("Downloaded %s -> %s (%d bytes).", name, manifest_path, len(content))
        return {"state": "downloaded", "validated_data": validated_data}

    def _validate_existing(
        self,
        target: Path,
        manifest_path: str,
        response_format: str,
        name: str,
        requested_url: str,
        query_parameters: dict[str, Any],
    ) -> dict[str, Any]:
        record = self.manifest.get(manifest_path)
        if record is None:
            raise AcquisitionError(
                f"Refusing to trust existing {manifest_path} without a manifest record."
            )
        if record.get("requested_url") != requested_url or record.get("query_parameters") != query_parameters:
            raise AcquisitionError(
                f"Existing file {manifest_path} belongs to a different source request."
            )
        content = target.read_bytes()
        validated_data = validate_content(content, response_format)
        checksum = hashlib.sha256(content).hexdigest()
        if checksum != record.get("sha256") or len(content) != record.get("response_bytes"):
            raise AcquisitionError(
                f"Existing file {manifest_path} differs from its manifest; it was not overwritten."
            )
        self.logger.info("Skipped verified existing %s (%s).", name, manifest_path)
        return {"state": "skipped", "validated_data": validated_data}

    def _request_with_retries(
        self,
        url: str,
        params: dict[str, Any],
        headers: dict[str, str],
        name: str,
    ) -> tuple[requests.Response, int]:
        for attempt in range(self.settings.max_retries + 1):
            self._respect_rate_limit()
            try:
                response = self.session.get(
                    url,
                    params=params,
                    headers=headers,
                    timeout=self.settings.timeout_seconds,
                    allow_redirects=True,
                )
            except requests.RequestException as error:
                if attempt >= self.settings.max_retries:
                    raise AcquisitionError(f"{name} failed after {attempt + 1} attempts: {error}") from error
                self._backoff(attempt, None)
                continue

            if response.status_code not in TRANSIENT_STATUS_CODES or attempt >= self.settings.max_retries:
                return response, attempt

            self.logger.warning(
                "%s returned transient HTTP %d; retrying (%d/%d).",
                name,
                response.status_code,
                attempt + 1,
                self.settings.max_retries,
            )
            self._backoff(attempt, response.headers.get("Retry-After"))

        raise AssertionError("Retry loop ended unexpectedly.")

    def _respect_rate_limit(self) -> None:
        now = time.monotonic()
        if self.last_request_started is not None:
            remaining = self.settings.minimum_interval_seconds - (now - self.last_request_started)
            if remaining > 0:
                time.sleep(remaining)
        self.last_request_started = time.monotonic()

    def _backoff(self, attempt: int, retry_after: str | None) -> None:
        delay = parse_retry_after(retry_after)
        if delay is None:
            delay = self.settings.backoff_seconds * (2**attempt)
        time.sleep(max(0.0, delay))


def validate_source_request(url: str, params: dict[str, Any]) -> None:
    parsed = urlsplit(url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise AcquisitionError(f"Unsupported source URL: {url}")
    if parsed.username or parsed.password:
        raise AcquisitionError("Source URLs must not contain credentials.")
    for key in params:
        lowered = str(key).lower()
        if any(part in lowered for part in SENSITIVE_QUERY_PARTS):
            raise AcquisitionError(f"Sensitive query parameter {key!r} is not allowed in a manifest.")


def resolve_target(root: Path, relative_path: str) -> Path:
    if not relative_path or Path(relative_path).is_absolute():
        raise AcquisitionError(f"Source path must be relative: {relative_path!r}")
    resolved_root = root.resolve()
    target = (resolved_root / relative_path).resolve()
    if not target.is_relative_to(resolved_root):
        raise AcquisitionError(f"Source path escapes source root: {relative_path!r}")
    return target


def accept_header(response_format: str) -> str:
    return {
        "json": "application/json",
        "xml": "application/xml, text/xml;q=0.9",
        "pdf": "application/pdf",
        "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "binary": "application/octet-stream, */*;q=0.5",
    }.get(response_format, "*/*")


def validate_content(content: bytes, response_format: str) -> Any:
    if not content:
        raise AcquisitionError("Source response is empty.")
    if response_format == "json":
        try:
            return json.loads(content.decode("utf-8-sig"))
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise AcquisitionError(f"Source response is not valid UTF-8 JSON: {error}") from error
    if response_format == "xml":
        try:
            return ET.fromstring(content)
        except ET.ParseError as error:
            raise AcquisitionError(f"Source response is not valid XML: {error}") from error
    if response_format == "pdf" and not content.startswith(b"%PDF-"):
        raise AcquisitionError("Source response is not a PDF document.")
    if response_format == "xlsx":
        try:
            with zipfile.ZipFile(io.BytesIO(content)) as workbook:
                required_parts = {"[Content_Types].xml", "xl/workbook.xml"}
                names = set(workbook.namelist())
                if not required_parts.issubset(names) or not any(
                    name.startswith("xl/worksheets/sheet") and name.endswith(".xml")
                    for name in names
                ):
                    raise AcquisitionError("Source response is not a complete XLSX workbook.")
                ET.fromstring(workbook.read("xl/workbook.xml"))
        except (zipfile.BadZipFile, KeyError, ET.ParseError) as error:
            raise AcquisitionError(f"Source response is not a valid XLSX workbook: {error}") from error
    return None


def validate_content_type(content_type: str, response_format: str) -> None:
    lowered = content_type.lower()
    expected = {
        "json": ("application/json", "text/json"),
        "xml": ("application/xml", "text/xml"),
        "pdf": ("application/pdf",),
        "xlsx": (
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "application/octet-stream",
        ),
        "binary": tuple(),
    }.get(response_format, tuple())
    if expected and not any(value in lowered for value in expected):
        raise AcquisitionError(
            f"Unexpected content type {content_type!r} for {response_format} response."
        )


def detect_has_more_pages(data: Any, page_size: int = 50) -> bool | None:
    if not isinstance(data, list):
        return None
    if not data:
        return False
    last = data[-1]
    if isinstance(last, dict) and "hasMorePages" in last:
        return bool(last["hasMorePages"])
    if len(data) < page_size:
        return False
    return None


def parse_retry_after(value: str | None) -> float | None:
    if value is None:
        return None
    try:
        return float(value)
    except ValueError:
        try:
            parsed = parsedate_to_datetime(value)
        except (TypeError, ValueError, OverflowError):
            return None
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return max(0.0, (parsed - datetime.now(timezone.utc)).total_seconds())


def load_plan(path: Path) -> dict[str, Any]:
    try:
        plan = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise AcquisitionError(f"Unable to load acquisition plan {path}: {error}") from error
    if plan.get("version") != 1 or not isinstance(plan.get("items"), list):
        raise AcquisitionError("Acquisition plan must have version 1 and an items array.")
    return plan


def run_plan(plan_path: Path, settings: DownloadSettings) -> DownloadSummary:
    plan = load_plan(plan_path)
    summary = DownloadSummary()
    downloader = SourceDownloader(settings)
    try:
        for item in plan["items"]:
            try:
                downloader.download_item(item, summary)
            except AcquisitionError:
                summary.failures += 1
                raise
    finally:
        downloader.close()
    return summary


def validate_snapshot(source_root: Path, manifest_path: Path) -> dict[str, int]:
    """Validate every successfully preserved file against its manifest and format."""

    manifest = ManifestStore(manifest_path)
    checked = 0
    total_bytes = 0
    for local_path, record in manifest.success_by_path.items():
        target = resolve_target(source_root, local_path)
        if not target.is_file():
            raise AcquisitionError(f"Manifest source file is missing: {local_path}")
        content = target.read_bytes()
        if len(content) != record.get("response_bytes"):
            raise AcquisitionError(f"Byte count mismatch: {local_path}")
        if hashlib.sha256(content).hexdigest() != record.get("sha256"):
            raise AcquisitionError(f"Checksum mismatch: {local_path}")

        suffix = target.suffix.lower()
        if suffix == ".json":
            validate_content(content, "json")
        elif suffix in {".xml", ".xsd"}:
            validate_content(content, "xml")
        elif suffix == ".pdf":
            validate_content(content, "pdf")
        elif suffix == ".xlsx":
            validate_content(content, "xlsx")
        checked += 1
        total_bytes += len(content)

    unresolved_errors = 0
    for record in manifest.records:
        if record.get("state") != "error":
            continue
        if str(record.get("local_path")) not in manifest.success_by_path:
            unresolved_errors += 1

    if unresolved_errors:
        raise AcquisitionError(f"Manifest contains {unresolved_errors} unresolved acquisition errors.")
    return {"files": checked, "bytes": total_bytes, "unresolved_errors": unresolved_errors}

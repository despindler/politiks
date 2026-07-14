"""Offline, provenance-aware import of Swiss Parliament source snapshots."""

from __future__ import annotations

import hashlib
import json
import sqlite3
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable


SOURCE_SYSTEM = "swiss_parliament_legacy_webservice"
SCHEMA_VERSION = "2.0.0"


def _date(value: Any) -> str | None:
    if not value:
        return None
    return str(value)[:10]


def _text(value: Any) -> str | None:
    if value is None:
        return None
    value = str(value).strip()
    return value or None


def _source_id(value: Any) -> str:
    return str(value)


def _name(record: dict[str, Any]) -> str:
    return " ".join(
        part for part in (_text(record.get("firstName")), _text(record.get("lastName"))) if part
    ) or "Unbekannte Person"


def _normal_choice(raw: Any) -> str:
    token = str(raw or "").strip().lower()
    return {
        "yes": "yes",
        "no": "no",
        "eh": "abstain",
        "es": "not_participating",
        "nt": "not_participating",
        "p": "presiding",
    }.get(token, "other")


AGGREGATE_CHOICE = {
    "1": "yes",
    "2": "no",
    "3": "abstain",
    "5": "not_participating",
    "6": "excused",
    "7": "presiding",
}


@dataclass
class ImportReport:
    snapshot_name: str
    source_files: int = 0
    source_records: int = 0
    normalized_files: int = 0
    skipped_files: list[dict[str, str]] = field(default_factory=list)
    table_counts: dict[str, int] = field(default_factory=dict)
    choice_counts: dict[str, int] = field(default_factory=dict)
    chamber_year_counts: list[dict[str, Any]] = field(default_factory=list)
    foreign_key_violations: int = 0
    unresolved_event_chambers: int = 0
    unlinked_event_matters: int = 0
    choices_without_dated_party: int = 0
    choices_without_dated_faction: int = 0

    def as_dict(self) -> dict[str, Any]:
        return {
            "snapshot_name": self.snapshot_name,
            "source_files": self.source_files,
            "source_records": self.source_records,
            "normalized_files": self.normalized_files,
            "skipped_files": self.skipped_files,
            "table_counts": self.table_counts,
            "choice_counts": self.choice_counts,
            "chamber_year_counts": self.chamber_year_counts,
            "foreign_key_violations": self.foreign_key_violations,
            "unresolved_event_chambers": self.unresolved_event_chambers,
            "unlinked_event_matters": self.unlinked_event_matters,
            "choices_without_dated_party": self.choices_without_dated_party,
            "choices_without_dated_faction": self.choices_without_dated_faction,
        }


class SwissSnapshotImporter:
    """Import one verified local manifest without making network requests."""

    def __init__(self, connection: sqlite3.Connection, source_root: Path, manifest_path: Path):
        self.connection = connection
        self.connection.row_factory = sqlite3.Row
        self.source_root = source_root
        self.manifest_path = manifest_path
        self.snapshot_name = manifest_path.stem
        self.report = ImportReport(snapshot_name=self.snapshot_name)
        self.country_id: int | None = None
        self.legislature_id: int | None = None

    def run(self) -> ImportReport:
        manifest = self._read_manifest()
        started_at = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
        cursor = self.connection.execute(
            """INSERT INTO import_run
               (snapshot_name, manifest_path, schema_version, started_at, status)
               VALUES (?, ?, ?, ?, 'running')""",
            (self.snapshot_name, self.manifest_path.as_posix(), SCHEMA_VERSION, started_at),
        )
        run_id = int(cursor.lastrowid)
        self._bootstrap_country()

        registered: list[tuple[dict[str, Any], int, list[tuple[int, dict[str, Any]]]]] = []
        for row in manifest:
            registered.append(self._register_file(run_id, row))

        for row, _file_id, records in registered:
            self._normalize_file(row, records)

        self.connection.execute(
            "UPDATE import_run SET completed_at = ?, status = 'completed' WHERE id = ?",
            (datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"), run_id),
        )
        self._collect_report()
        return self.report

    def _read_manifest(self) -> list[dict[str, Any]]:
        rows = []
        with self.manifest_path.open("r", encoding="utf-8") as handle:
            for line_number, line in enumerate(handle, start=1):
                if not line.strip():
                    continue
                row = json.loads(line)
                if row.get("state") != "success":
                    raise ValueError(f"Manifest row {line_number} is not a successful retrieval")
                rows.append(row)
        if not rows:
            raise ValueError("Manifest contains no successful source files")
        return rows

    def _register_file(
        self, run_id: int, row: dict[str, Any]
    ) -> tuple[dict[str, Any], int, list[tuple[int, dict[str, Any]]]]:
        path = self.source_root / row["local_path"]
        raw = path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        if digest != row["sha256"] or len(raw) != int(row["response_bytes"]):
            raise ValueError(f"Checksum or byte count mismatch for {path}")

        is_json = path.suffix.lower() == ".json"
        cursor = self.connection.execute(
            """INSERT INTO source_file
               (import_run_id, endpoint, local_path, requested_url, final_url,
                request_parameters_json, retrieved_at, http_status, content_type,
                byte_count, sha256, attribution, manifest_state, is_json)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                run_id,
                row["endpoint"],
                row["local_path"],
                row.get("requested_url"),
                row.get("source_url"),
                json.dumps(row.get("query_parameters", {}), ensure_ascii=False, sort_keys=True),
                row.get("retrieval_timestamp_utc"),
                row.get("http_status"),
                row.get("response_content_type"),
                len(raw),
                digest,
                row.get("attribution"),
                row["state"],
                int(is_json),
            ),
        )
        file_id = int(cursor.lastrowid)
        self.report.source_files += 1
        records: list[tuple[int, dict[str, Any]]] = []
        if is_json:
            payload = json.loads(raw.decode("utf-8"))
            items = payload if isinstance(payload, list) else [payload]
            for index, record in enumerate(items):
                if not isinstance(record, dict):
                    self.report.skipped_files.append(
                        {"endpoint": row["endpoint"], "reason": "JSON item is not an object"}
                    )
                    continue
                source_identifier = record.get("id")
                record_cursor = self.connection.execute(
                    """INSERT INTO source_record
                       (source_file_id, record_index, record_kind, source_identifier, raw_json)
                       VALUES (?, ?, ?, ?, ?)""",
                    (
                        file_id,
                        index,
                        row["endpoint"],
                        None if source_identifier is None else str(source_identifier),
                        json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":")),
                    ),
                )
                records.append((int(record_cursor.lastrowid), record))
                self.report.source_records += 1
        else:
            self.report.skipped_files.append(
                {"endpoint": row["endpoint"], "reason": "registered for provenance; no JSON normalization"}
            )
        return row, file_id, records

    def _bootstrap_country(self) -> None:
        self.country_id = int(
            self.connection.execute(
                "INSERT INTO country (iso2, iso3, name_de) VALUES ('CH', 'CHE', 'Schweiz') RETURNING id"
            ).fetchone()[0]
        )
        self.legislature_id = int(
            self.connection.execute(
                """INSERT INTO legislature
                   (country_id, source_system, source_identifier, name)
                   VALUES (?, ?, 'federal_assembly', 'Bundesversammlung') RETURNING id""",
                (self.country_id, SOURCE_SYSTEM),
            ).fetchone()[0]
        )

    def _normalize_file(
        self, row: dict[str, Any], records: list[tuple[int, dict[str, Any]]]
    ) -> None:
        endpoint = row["endpoint"]
        if not records:
            return
        handler = None
        if endpoint == "councils":
            handler = self._council
        elif endpoint == "legislative_periods":
            handler = self._legislative_period
        elif endpoint.startswith("sessions_page_"):
            handler = self._session
        elif endpoint == "cantons":
            handler = self._subdivision
        elif endpoint.startswith("committees_page_"):
            handler = self._committee
        elif endpoint.startswith("factions_"):
            handler = self._faction
        elif endpoint.startswith("parties_historic_page_"):
            handler = self._party
        elif endpoint == "affair_types":
            handler = self._matter_type
        elif endpoint == "affair_states":
            handler = self._matter_state
        elif endpoint == "affair_topics":
            handler = self._topic
        elif endpoint == "affair_descriptors":
            handler = self._descriptor
        elif endpoint == "councillors_basic_details":
            handler = self._basic_person
        elif endpoint.startswith("councillors_historic_page_"):
            handler = self._historic_person
        elif endpoint.startswith("councillor_") and endpoint != "councillors_basic_details":
            handler = self._person_detail
        elif endpoint.startswith("councillors_page_"):
            handler = self._person_list_record
        elif endpoint.startswith("vote_councillors_page_"):
            handler = self._vote_person
        elif endpoint.startswith("affairs_list_first_two_pages"):
            handler = self._matter_stub
        elif endpoint.startswith("affair_summaries_page_"):
            handler = self._summary_stub
        elif endpoint.startswith("affair_summary_"):
            handler = self._summary_detail
        elif endpoint.startswith("affair_") and endpoint[7:].isdigit():
            handler = self._matter_detail
        elif endpoint.startswith("vote_affairs_recent_page_"):
            handler = self._vote_matter_stub
        elif endpoint.startswith("vote_affair_"):
            handler = self._vote_affair_detail
        elif endpoint.startswith("vote_councillor_"):
            handler = self._vote_councillor_detail

        if handler is None:
            self.report.skipped_files.append(
                {"endpoint": endpoint, "reason": "JSON retained in source_record; no normalized adapter"}
            )
            return
        for source_record_id, record in records:
            handler(source_record_id, record)
        self.report.normalized_files += 1

    def _lookup(self, table: str, source_identifier: Any) -> int | None:
        row = self.connection.execute(
            f"SELECT id FROM {table} WHERE source_system = ? AND source_identifier = ?",
            (SOURCE_SYSTEM, str(source_identifier)),
        ).fetchone()
        return int(row[0]) if row else None

    def _council(self, source_record_id: int, record: dict[str, Any]) -> None:
        self.connection.execute(
            """INSERT INTO chamber
               (legislature_id, source_system, source_identifier, code, abbreviation, name, chamber_type)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 code=excluded.code, abbreviation=excluded.abbreviation,
                 name=excluded.name, chamber_type=excluded.chamber_type""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("abbreviation"),
                record.get("name") or _source_id(record["id"]),
                record.get("type"),
            ),
        )

    def _legislative_period(self, source_record_id: int, record: dict[str, Any]) -> None:
        self.connection.execute(
            """INSERT INTO legislative_period
               (legislature_id, source_system, source_identifier, code, name, date_from, date_to)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 code=excluded.code, name=excluded.name,
                 date_from=excluded.date_from, date_to=excluded.date_to""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("name") or _source_id(record["id"]),
                _date(record.get("from")),
                _date(record.get("to")),
            ),
        )

    def _session(self, source_record_id: int, record: dict[str, Any]) -> None:
        period_id = None
        code = str(record.get("code") or "")
        if len(code) >= 2 and code[:2].isdigit():
            period_id = self._lookup("legislative_period", int(code[:2]))
        self.connection.execute(
            """INSERT INTO parliamentary_session
               (legislature_id, legislative_period_id, source_system, source_identifier,
                code, name, date_from, date_to)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 legislative_period_id=COALESCE(excluded.legislative_period_id, legislative_period_id),
                 code=excluded.code, name=excluded.name,
                 date_from=excluded.date_from, date_to=excluded.date_to""",
            (
                self.legislature_id,
                period_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("name") or _source_id(record["id"]),
                _date(record.get("from")),
                _date(record.get("to")),
            ),
        )

    def _subdivision(self, source_record_id: int, record: dict[str, Any]) -> None:
        self.connection.execute(
            """INSERT INTO subdivision
               (country_id, source_system, source_identifier, code, abbreviation, name)
               VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 code=excluded.code, abbreviation=excluded.abbreviation, name=excluded.name""",
            (
                self.country_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("abbreviation"),
                record.get("name") or _source_id(record["id"]),
            ),
        )

    def _committee(self, source_record_id: int, record: dict[str, Any]) -> None:
        council = record.get("council") or {}
        chamber_id = self._lookup("chamber", council.get("id")) if council.get("id") else None
        self.connection.execute(
            """INSERT INTO committee
               (legislature_id, chamber_id, source_system, source_identifier, code,
                abbreviation, name, committee_type, valid_from, valid_to, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 chamber_id=COALESCE(excluded.chamber_id, chamber_id), code=excluded.code,
                 abbreviation=excluded.abbreviation, name=excluded.name,
                 committee_type=excluded.committee_type, valid_from=excluded.valid_from,
                 valid_to=excluded.valid_to""",
            (
                self.legislature_id,
                chamber_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("abbreviation"),
                record.get("name") or _source_id(record["id"]),
                _text(record.get("typeCode")),
                _date(record.get("from")),
                _date(record.get("to")),
                source_record_id,
            ),
        )

    def _party(self, source_record_id: int, record: dict[str, Any]) -> None:
        if str(record.get("id")) == "0":
            return
        self.connection.execute(
            """INSERT INTO political_party
               (country_id, source_system, source_identifier, code, abbreviation, name, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 code=excluded.code, abbreviation=excluded.abbreviation,
                 name=excluded.name, source_record_id=excluded.source_record_id""",
            (
                self.country_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("abbreviation"),
                record.get("name") or record.get("abbreviation") or _source_id(record["id"]),
                source_record_id,
            ),
        )

    def _faction(self, source_record_id: int, record: dict[str, Any]) -> None:
        if str(record.get("id")) == "0":
            return
        self.connection.execute(
            """INSERT INTO parliamentary_faction
               (legislature_id, source_system, source_identifier, code, abbreviation,
                short_name, name, valid_from, valid_to, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 code=excluded.code, abbreviation=excluded.abbreviation,
                 short_name=excluded.short_name, name=excluded.name,
                 valid_from=COALESCE(excluded.valid_from, valid_from),
                 valid_to=COALESCE(excluded.valid_to, valid_to)""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("code"),
                record.get("abbreviation"),
                record.get("shortName"),
                record.get("name") or record.get("abbreviation") or _source_id(record["id"]),
                _date(record.get("from")),
                _date(record.get("to")),
                source_record_id,
            ),
        )

    def _simple_reference(
        self, table: str, source_record_id: int, record: dict[str, Any], extra: str = ""
    ) -> None:
        columns = "legislature_id, source_system, source_identifier, code, name, source_record_id"
        values: list[Any] = [
            self.legislature_id,
            SOURCE_SYSTEM,
            _source_id(record["id"]),
            record.get("code"),
            record.get("name") or _source_id(record["id"]),
            source_record_id,
        ]
        if extra:
            columns = columns.replace("name,", f"{extra}, name,")
            values.insert(4, record.get(extra))
        placeholders = ", ".join("?" for _ in values)
        self.connection.execute(
            f"""INSERT INTO {table} ({columns}) VALUES ({placeholders})
                ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                  code=excluded.code, name=excluded.name, source_record_id=excluded.source_record_id""",
            values,
        )

    def _matter_type(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._simple_reference("matter_type", source_record_id, record, "abbreviation")

    def _matter_state(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._simple_reference("matter_state", source_record_id, record)

    def _topic(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._simple_reference("official_topic", source_record_id, record)

    def _descriptor(self, source_record_id: int, record: dict[str, Any]) -> None:
        self.connection.execute(
            """INSERT INTO official_descriptor
               (legislature_id, source_system, source_identifier, descriptor_type, name, source_record_id)
               VALUES (?, ?, ?, NULL, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 name=excluded.name, source_record_id=excluded.source_record_id""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                _source_id(record["id"]),
                record.get("name") or _source_id(record["id"]),
                source_record_id,
            ),
        )

    def _person_by_identifier(self, namespace: str, identifier: Any) -> int | None:
        row = self.connection.execute(
            """SELECT person_id FROM person_identifier
               WHERE source_system = ? AND namespace = ? AND identifier = ?""",
            (SOURCE_SYSTEM, namespace, str(identifier)),
        ).fetchone()
        return int(row[0]) if row else None

    def _ensure_person(
        self,
        source_record_id: int,
        record: dict[str, Any],
        namespace: str,
        identifier: Any,
        resolution_method: str = "direct",
    ) -> int:
        person_id = self._person_by_identifier(namespace, identifier)
        if person_id is None:
            cursor = self.connection.execute(
                """INSERT INTO person
                   (country_id, first_name, last_name, display_name, gender,
                    birth_date, death_date, is_placeholder, source_record_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                (
                    self.country_id,
                    _text(record.get("firstName")),
                    _text(record.get("lastName")),
                    _name(record),
                    _text(record.get("gender")),
                    _date(record.get("birthDate")),
                    _date(record.get("dateOfDeath")),
                    int(namespace == "voting_councillor_number" and not record.get("firstName")),
                    source_record_id,
                ),
            )
            person_id = int(cursor.lastrowid)
            self._add_person_identifier(
                person_id, namespace, identifier, source_record_id, resolution_method
            )
        else:
            self.connection.execute(
                """UPDATE person SET
                   first_name=COALESCE(?, first_name), last_name=COALESCE(?, last_name),
                   display_name=CASE WHEN ? <> 'Unbekannte Person' THEN ? ELSE display_name END,
                   gender=COALESCE(?, gender), birth_date=COALESCE(?, birth_date),
                   death_date=COALESCE(?, death_date), is_placeholder=0
                   WHERE id=?""",
                (
                    _text(record.get("firstName")),
                    _text(record.get("lastName")),
                    _name(record),
                    _name(record),
                    _text(record.get("gender")),
                    _date(record.get("birthDate")),
                    _date(record.get("dateOfDeath")),
                    person_id,
                ),
            )
        return person_id

    def _add_person_identifier(
        self,
        person_id: int,
        namespace: str,
        identifier: Any,
        source_record_id: int,
        resolution_method: str = "direct",
    ) -> None:
        if identifier is None:
            return
        existing = self._person_by_identifier(namespace, identifier)
        if existing is not None and existing != person_id:
            raise ValueError(
                f"Identifier collision for {namespace}:{identifier}: persons {existing} and {person_id}"
            )
        self.connection.execute(
            """INSERT OR IGNORE INTO person_identifier
               (person_id, source_system, namespace, identifier, resolution_method, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (person_id, SOURCE_SYSTEM, namespace, str(identifier), resolution_method, source_record_id),
        )

    def _basic_person(self, source_record_id: int, record: dict[str, Any]) -> None:
        person_id = self._ensure_person(source_record_id, record, "cv_person_id", record["id"])
        self._add_person_identifier(
            person_id, "voting_councillor_number", record.get("number"), source_record_id
        )

    def _person_list_record(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._ensure_person(source_record_id, record, "cv_person_id", record["id"])

    def _historic_person(self, source_record_id: int, record: dict[str, Any]) -> None:
        person_id = self._ensure_person(source_record_id, record, "cv_person_id", record["id"])
        membership = record.get("membership") or {}
        start, end = _date(membership.get("entryDate")), _date(membership.get("leavingDate"))
        council = record.get("council") or {}
        chamber_id = self._lookup("chamber", council.get("id")) if council.get("id") else None
        subdivision = record.get("canton") or {}
        subdivision_id = (
            self._lookup("subdivision", subdivision.get("id")) if subdivision.get("id") else None
        )
        if chamber_id:
            self.connection.execute(
                """INSERT OR IGNORE INTO person_mandate
                   (person_id, chamber_id, subdivision_id, date_from, date_to, role_name,
                    source_system, source_identifier, source_record_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                (
                    person_id,
                    chamber_id,
                    subdivision_id,
                    start,
                    end,
                    record.get("function"),
                    SOURCE_SYSTEM,
                    _text(membership.get("id")),
                    source_record_id,
                ),
            )
        party = record.get("party") or {}
        party_id = self._lookup("political_party", party.get("id")) if party.get("id") else None
        if party_id:
            self._membership(
                "person_party_membership",
                "party_id",
                person_id,
                party_id,
                start,
                end,
                False,
                "explicit_historic_membership_interval",
                source_record_id,
            )
        faction = record.get("faction") or {}
        faction_id = (
            self._lookup("parliamentary_faction", faction.get("id")) if faction.get("id") else None
        )
        if faction_id:
            self._membership(
                "person_faction_membership",
                "faction_id",
                person_id,
                faction_id,
                start,
                end,
                False,
                "explicit_historic_membership_interval",
                source_record_id,
            )

    def _find_by_abbreviation(self, table: str, abbreviation: Any) -> int | None:
        if not abbreviation:
            return None
        row = self.connection.execute(
            f"SELECT id FROM {table} WHERE abbreviation = ? ORDER BY id LIMIT 1", (str(abbreviation),)
        ).fetchone()
        return int(row[0]) if row else None

    def _placeholder_party(self, abbreviation: str, source_record_id: int) -> int:
        party_id = self._find_by_abbreviation("political_party", abbreviation)
        if party_id:
            return party_id
        cursor = self.connection.execute(
            """INSERT INTO political_party
               (country_id, source_system, source_identifier, abbreviation, name, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (
                self.country_id,
                SOURCE_SYSTEM,
                f"abbreviation:{abbreviation}",
                abbreviation,
                abbreviation,
                source_record_id,
            ),
        )
        return int(cursor.lastrowid)

    def _placeholder_faction(self, abbreviation: str, source_record_id: int) -> int:
        faction_id = self._find_by_abbreviation("parliamentary_faction", abbreviation)
        if faction_id:
            return faction_id
        cursor = self.connection.execute(
            """INSERT INTO parliamentary_faction
               (legislature_id, source_system, source_identifier, abbreviation, name, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                f"abbreviation:{abbreviation}",
                abbreviation,
                abbreviation,
                source_record_id,
            ),
        )
        return int(cursor.lastrowid)

    def _person_detail(self, source_record_id: int, record: dict[str, Any]) -> None:
        person_id = self._ensure_person(source_record_id, record, "cv_person_id", record["id"])
        self._add_person_identifier(
            person_id, "voting_councillor_number", record.get("number"), source_record_id
        )
        mandate_dates: list[tuple[str | None, str | None]] = []
        for membership in record.get("councilMemberships") or []:
            council = membership.get("council") or {}
            chamber_id = self._lookup("chamber", council.get("id"))
            if not chamber_id:
                continue
            start, end = _date(membership.get("entryDate")), _date(membership.get("leavingDate"))
            mandate_dates.append((start, end))
            subdivision_id = None
            canton = membership.get("canton")
            if canton:
                row = self.connection.execute(
                    "SELECT id FROM subdivision WHERE abbreviation = ?", (canton,)
                ).fetchone()
                subdivision_id = int(row[0]) if row else None
            self.connection.execute(
                """INSERT OR IGNORE INTO person_mandate
                   (person_id, chamber_id, subdivision_id, date_from, date_to,
                    source_system, source_identifier, source_record_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
                (
                    person_id,
                    chamber_id,
                    subdivision_id,
                    start,
                    end,
                    SOURCE_SYSTEM,
                    _text(membership.get("id")),
                    source_record_id,
                ),
            )
        for membership in record.get("committeeMemberships") or []:
            nested = membership.get("committee") or {}
            committee_id = self._lookup("committee", nested.get("id"))
            if committee_id:
                self.connection.execute(
                    """INSERT OR IGNORE INTO person_committee_membership
                       (person_id, committee_id, date_from, date_to, role_name, source_record_id)
                       VALUES (?, ?, ?, ?, ?, ?)""",
                    (
                        person_id,
                        committee_id,
                        _date(membership.get("entryDate")),
                        _date(membership.get("leavingDate")),
                        membership.get("function"),
                        source_record_id,
                    ),
                )
        # The detail endpoint gives only the current party/faction. Applying that
        # value to the profile's mandate span is useful but explicitly marked as
        # inferred; it must never be presented as source-confirmed history.
        if mandate_dates and record.get("party"):
            party_id = self._placeholder_party(str(record["party"]), source_record_id)
            for start, end in mandate_dates:
                self._membership(
                    "person_party_membership",
                    "party_id",
                    person_id,
                    party_id,
                    start,
                    end,
                    True,
                    "inferred_current_profile_party_over_mandate",
                    source_record_id,
                )
        if mandate_dates and record.get("faction"):
            faction_id = self._placeholder_faction(str(record["faction"]), source_record_id)
            for start, end in mandate_dates:
                self._membership(
                    "person_faction_membership",
                    "faction_id",
                    person_id,
                    faction_id,
                    start,
                    end,
                    True,
                    "inferred_current_profile_faction_over_mandate",
                    source_record_id,
                )

    def _membership(
        self,
        table: str,
        target_column: str,
        person_id: int,
        target_id: int,
        start: str | None,
        end: str | None,
        inferred: bool,
        basis: str,
        source_record_id: int,
    ) -> None:
        self.connection.execute(
            f"""INSERT OR IGNORE INTO {table}
                (person_id, {target_column}, date_from, date_to, is_inferred,
                 evidence_basis, source_record_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (person_id, target_id, start, end, int(inferred), basis, source_record_id),
        )

    def _vote_person(self, source_record_id: int, record: dict[str, Any]) -> None:
        person_id = self._ensure_person(
            source_record_id, record, "voting_councillor_number", record["id"]
        )
        self._add_person_identifier(person_id, "elan_id", record.get("elanId"), source_record_id)

    def _ensure_matter(
        self,
        source_record_id: int,
        identifier: Any,
        title: str | None = None,
        formatted: str | None = None,
        updated: str | None = None,
    ) -> int:
        source_identifier = str(identifier)
        self.connection.execute(
            """INSERT INTO parliamentary_matter
               (legislature_id, source_system, source_identifier, formatted_identifier,
                title, source_updated_at, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 formatted_identifier=COALESCE(excluded.formatted_identifier, formatted_identifier),
                 title=COALESCE(excluded.title, title),
                 source_updated_at=COALESCE(excluded.source_updated_at, source_updated_at)""",
            (
                self.legislature_id,
                SOURCE_SYSTEM,
                source_identifier,
                formatted,
                title,
                updated,
                source_record_id,
            ),
        )
        return int(self._lookup("parliamentary_matter", source_identifier))

    def _matter_stub(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._ensure_matter(
            source_record_id,
            record["id"],
            formatted=record.get("shortId"),
            updated=record.get("updated"),
        )

    def _summary_stub(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._ensure_matter(
            source_record_id,
            record["id"],
            title=record.get("title"),
            formatted=record.get("formattedId"),
            updated=record.get("updated"),
        )

    def _vote_matter_stub(self, source_record_id: int, record: dict[str, Any]) -> None:
        self._ensure_matter(
            source_record_id, record["id"], title=record.get("title"), updated=record.get("updated")
        )

    def _matter_detail(self, source_record_id: int, record: dict[str, Any]) -> None:
        matter_id = self._ensure_matter(
            source_record_id,
            record["id"],
            title=record.get("title"),
            formatted=record.get("shortId"),
            updated=record.get("updated"),
        )
        affair_type = record.get("affairType") or {}
        state = record.get("state") or {}
        deposit = record.get("deposit") or {}
        council = deposit.get("council") or {}
        type_id = self._lookup("matter_type", affair_type.get("id")) if affair_type.get("id") else None
        state_id = self._lookup("matter_state", state.get("id")) if state.get("id") else None
        chamber_id = self._lookup("chamber", council.get("id")) if council.get("id") else None
        session_id = self._lookup("parliamentary_session", deposit.get("session"))
        period_id = self._lookup("legislative_period", deposit.get("legislativePeriod"))
        self.connection.execute(
            """UPDATE parliamentary_matter SET matter_type_id=?, matter_state_id=?,
               submitted_chamber_id=?, submitted_session_id=?, submitted_period_id=?,
               submitted_at=? WHERE id=?""",
            (
                type_id,
                state_id,
                chamber_id,
                session_id,
                period_id,
                _date(deposit.get("date")),
                matter_id,
            ),
        )
        language = record.get("language")
        for item in record.get("texts") or []:
            text_type = item.get("type") or {}
            body = item.get("value")
            if body is None:
                continue
            self.connection.execute(
                """INSERT OR IGNORE INTO matter_text
                   (matter_id, language, text_type_identifier, text_type_name, body_html, source_record_id)
                   VALUES (?, ?, ?, ?, ?, ?)""",
                (
                    matter_id,
                    language,
                    _text(text_type.get("id")),
                    text_type.get("name"),
                    body,
                    source_record_id,
                ),
            )
        for descriptor in record.get("descriptors") or []:
            key = descriptor.get("key")
            if key is None:
                continue
            descriptor_id = self._lookup("official_descriptor", key)
            if descriptor_id is None:
                self._descriptor(
                    source_record_id,
                    {"id": key, "name": descriptor.get("name") or str(key)},
                )
                descriptor_id = self._lookup("official_descriptor", key)
                self.connection.execute(
                    "UPDATE official_descriptor SET descriptor_type=? WHERE id=?",
                    (_text(descriptor.get("type")), descriptor_id),
                )
            self.connection.execute(
                "INSERT OR IGNORE INTO matter_descriptor (matter_id, descriptor_id, source_record_id) VALUES (?, ?, ?)",
                (matter_id, descriptor_id, source_record_id),
            )
        indexing = str(record.get("additionalIndexing") or "")
        for token in indexing.split(";"):
            if not token.isdigit():
                break
            topic_id = self._lookup("official_topic", token)
            if topic_id:
                self.connection.execute(
                    "INSERT OR IGNORE INTO matter_topic (matter_id, topic_id, source_record_id) VALUES (?, ?, ?)",
                    (matter_id, topic_id, source_record_id),
                )

    def _summary_detail(self, source_record_id: int, record: dict[str, Any]) -> None:
        matter_id = self._ensure_matter(
            source_record_id,
            record["id"],
            title=record.get("title"),
            formatted=record.get("formattedId"),
            updated=record.get("updated"),
        )
        self.connection.execute(
            """INSERT INTO matter_summary
               (matter_id, language, description_html, initial_situation_html,
                proceedings_html, source_record_id)
               VALUES (?, 'de', ?, ?, ?, ?)
               ON CONFLICT(matter_id, language) DO UPDATE SET
                 description_html=excluded.description_html,
                 initial_situation_html=excluded.initial_situation_html,
                 proceedings_html=excluded.proceedings_html,
                 source_record_id=excluded.source_record_id""",
            (
                matter_id,
                record.get("description"),
                record.get("initialSituation"),
                record.get("proceedings"),
                source_record_id,
            ),
        )

    def _ensure_event(
        self,
        source_record_id: int,
        matter_id: int,
        record: dict[str, Any],
        source_identifier: Any | None = None,
    ) -> int:
        identifier = str(source_identifier if source_identifier is not None else record["id"])
        occurred_at = record.get("date")
        if not occurred_at:
            raise ValueError(f"Voting event {identifier} has no date")
        self.connection.execute(
            """INSERT INTO voting_event
               (matter_id, source_system, source_identifier, registration_number,
                occurred_at, division_text, submission_text, meaning_yes, meaning_no,
                chamber_resolution_basis, source_record_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unresolved_source_omits_chamber', ?)
               ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                 matter_id=COALESCE(excluded.matter_id, matter_id),
                 registration_number=COALESCE(excluded.registration_number, registration_number),
                 occurred_at=excluded.occurred_at,
                 division_text=COALESCE(excluded.division_text, division_text),
                 submission_text=COALESCE(excluded.submission_text, submission_text),
                 meaning_yes=COALESCE(excluded.meaning_yes, meaning_yes),
                 meaning_no=COALESCE(excluded.meaning_no, meaning_no)""",
            (
                matter_id,
                SOURCE_SYSTEM,
                identifier,
                _text(record.get("registrationNumber")),
                occurred_at,
                record.get("divisionText"),
                record.get("submissionText"),
                record.get("meaningYes"),
                record.get("meaningNo"),
                source_record_id,
            ),
        )
        return int(self._lookup("voting_event", identifier))

    def _person_for_vote(self, source_record_id: int, record: dict[str, Any]) -> int:
        number = record.get("number")
        if number is None:
            raise ValueError("Individual voting choice has no councillor number")
        person_id = self._ensure_person(
            source_record_id, record, "voting_councillor_number", number
        )
        self._add_person_identifier(person_id, "elan_id", record.get("elanId"), source_record_id)
        return person_id

    def _vote_affair_detail(self, source_record_id: int, record: dict[str, Any]) -> None:
        matter_id = self._ensure_matter(
            source_record_id, record["id"], title=record.get("title"), updated=record.get("updated")
        )
        for event in record.get("affairVotes") or []:
            event_id = self._ensure_event(source_record_id, matter_id, event)
            for scope, key in (("total", "totalVotes"), ("filtered", "filteredTotalVotes")):
                for aggregate in event.get(key) or []:
                    source_code = str(aggregate.get("type"))
                    self.connection.execute(
                        """INSERT OR REPLACE INTO voting_aggregate
                           (voting_event_id, aggregate_scope, source_choice_code,
                            normalized_choice, vote_count, mapping_is_inferred)
                           VALUES (?, ?, ?, ?, ?, 1)""",
                        (
                            event_id,
                            scope,
                            source_code,
                            AGGREGATE_CHOICE.get(source_code),
                            int(aggregate.get("count") or 0),
                        ),
                    )
            for choice in event.get("councillorVotes") or []:
                person_id = self._person_for_vote(source_record_id, choice)
                self.connection.execute(
                    """INSERT INTO voting_choice
                       (voting_event_id, person_id, source_system, source_identifier,
                        raw_decision, normalized_choice, source_record_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                         voting_event_id=excluded.voting_event_id, person_id=excluded.person_id,
                         raw_decision=excluded.raw_decision,
                         normalized_choice=excluded.normalized_choice""",
                    (
                        event_id,
                        person_id,
                        SOURCE_SYSTEM,
                        str(choice["id"]),
                        str(choice.get("decision") or ""),
                        _normal_choice(choice.get("decision")),
                        source_record_id,
                    ),
                )

    def _vote_councillor_detail(self, source_record_id: int, record: dict[str, Any]) -> None:
        person_id = self._ensure_person(
            source_record_id, record, "voting_councillor_number", record["id"]
        )
        self._add_person_identifier(person_id, "elan_id", record.get("elanId"), source_record_id)
        for event in record.get("affairVotes") or []:
            matter_id = self._ensure_matter(
                source_record_id, event["affairId"], title=event.get("affairTitle")
            )
            event_id = self._ensure_event(source_record_id, matter_id, event)
            choice = event.get("councillorVote") or {}
            if choice.get("id") is None:
                continue
            self.connection.execute(
                """INSERT INTO voting_choice
                   (voting_event_id, person_id, source_system, source_identifier,
                    raw_decision, normalized_choice, source_record_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(source_system, source_identifier) DO UPDATE SET
                     voting_event_id=excluded.voting_event_id, person_id=excluded.person_id,
                     raw_decision=excluded.raw_decision,
                     normalized_choice=excluded.normalized_choice""",
                (
                    event_id,
                    person_id,
                    SOURCE_SYSTEM,
                    str(choice["id"]),
                    str(choice.get("decision") or ""),
                    _normal_choice(choice.get("decision")),
                    source_record_id,
                ),
            )

    def _collect_report(self) -> None:
        tables = (
            "source_file",
            "source_record",
            "country",
            "legislature",
            "chamber",
            "legislative_period",
            "parliamentary_session",
            "subdivision",
            "committee",
            "political_party",
            "parliamentary_faction",
            "person",
            "person_identifier",
            "person_mandate",
            "person_party_membership",
            "person_faction_membership",
            "parliamentary_matter",
            "matter_text",
            "matter_summary",
            "official_topic",
            "official_descriptor",
            "matter_topic",
            "matter_descriptor",
            "voting_event",
            "voting_aggregate",
            "voting_choice",
        )
        self.report.table_counts = {
            table: int(self.connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0])
            for table in tables
        }
        self.report.choice_counts = {
            str(row["normalized_choice"]): int(row["amount"])
            for row in self.connection.execute(
                """SELECT normalized_choice, COUNT(*) AS amount
                   FROM voting_choice GROUP BY normalized_choice ORDER BY normalized_choice"""
            )
        }
        self.report.chamber_year_counts = [
            dict(row)
            for row in self.connection.execute(
                """SELECT COALESCE(c.name, 'Nicht aufgelöst') AS chamber,
                          substr(v.occurred_at, 1, 4) AS year, COUNT(*) AS voting_events
                   FROM voting_event v LEFT JOIN chamber c ON c.id = v.chamber_id
                   GROUP BY chamber, year ORDER BY year, chamber"""
            )
        ]
        self.report.foreign_key_violations = len(
            self.connection.execute("PRAGMA foreign_key_check").fetchall()
        )
        self.report.unresolved_event_chambers = int(
            self.connection.execute(
                "SELECT COUNT(*) FROM voting_event WHERE chamber_id IS NULL"
            ).fetchone()[0]
        )
        self.report.unlinked_event_matters = int(
            self.connection.execute(
                "SELECT COUNT(*) FROM voting_event WHERE matter_id IS NULL"
            ).fetchone()[0]
        )
        valid_party = """EXISTS (
            SELECT 1 FROM person_party_membership ppm
            WHERE ppm.person_id = vc.person_id
              AND (ppm.date_from IS NULL OR ppm.date_from <= substr(ve.occurred_at, 1, 10))
              AND (ppm.date_to IS NULL OR ppm.date_to >= substr(ve.occurred_at, 1, 10))
        )"""
        valid_faction = valid_party.replace("person_party_membership ppm", "person_faction_membership ppm")
        self.report.choices_without_dated_party = int(
            self.connection.execute(
                f"""SELECT COUNT(*) FROM voting_choice vc
                     JOIN voting_event ve ON ve.id=vc.voting_event_id WHERE NOT {valid_party}"""
            ).fetchone()[0]
        )
        self.report.choices_without_dated_faction = int(
            self.connection.execute(
                f"""SELECT COUNT(*) FROM voting_choice vc
                     JOIN voting_event ve ON ve.id=vc.voting_event_id WHERE NOT {valid_faction}"""
            ).fetchone()[0]
        )


def recreate_database(
    repository_root: Path,
    manifest_path: Path | None = None,
    database_path: Path | None = None,
) -> ImportReport:
    """Recreate the research database from schema and a local source manifest."""

    repository_root = repository_root.resolve()
    manifest_path = (manifest_path or repository_root / "source/manifests/fixture.jsonl").resolve()
    database_path = (database_path or repository_root / "database/parliament.sqlite").resolve()
    schema_path = repository_root / "database/schema.sql"
    source_root = repository_root / "source"
    database_path.parent.mkdir(parents=True, exist_ok=True)
    database_path.unlink(missing_ok=True)

    connection = sqlite3.connect(database_path)
    try:
        connection.execute("PRAGMA foreign_keys = ON")
        connection.executescript(schema_path.read_text(encoding="utf-8"))
        with connection:
            report = SwissSnapshotImporter(connection, source_root, manifest_path).run()
        return report
    except Exception:
        connection.rollback()
        connection.close()
        database_path.unlink(missing_ok=True)
        raise
    finally:
        try:
            connection.close()
        except sqlite3.Error:
            pass


def query_rows(database_path: Path, sql: str, parameters: Iterable[Any] = ()) -> list[dict[str, Any]]:
    """Return small notebook/report queries as dictionaries."""

    connection = sqlite3.connect(database_path)
    connection.row_factory = sqlite3.Row
    try:
        return [dict(row) for row in connection.execute(sql, tuple(parameters))]
    finally:
        connection.close()

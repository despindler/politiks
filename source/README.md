# Raw source data

This directory contains substantively unmodified responses from official parliamentary sources, retrieval manifests, acquisition plans, and source documentation.

## Layout

```text
source/
├── documentation/                 # Human-readable observed behavior and coverage
├── manifests/                     # One JSON object per request outcome
├── plans/                         # Declarative immutable snapshot plans
└── snapshots/
    └── fixture_2026-07-14/        # Small research/development fixture
```

Paths in a manifest are relative to `source/`. Every successful row records the requested and final source URL, query parameters, UTC retrieval time, HTTP status, content type, byte count, SHA256, retry count, attribution, and local path. Failed requests are recorded with `state: error` but never promoted to a final source file.

## Acquire or resume the fixture

From the repository root:

```powershell
.\.venv\Scripts\python.exe scripts/download_sources.py `
  --plan source/plans/fixture.json `
  --manifest source/manifests/fixture.jsonl
```

The downloader writes a `.part` file only after validating the response in memory, flushes it, and atomically replaces the final path. A rerun verifies existing files against the manifest and skips them without adding duplicate success rows.

Validate the complete preserved fixture:

```powershell
.\.venv\Scripts\python.exe scripts/validate_source_snapshot.py `
  --manifest source/manifests/fixture.jsonl
```

Create a new dated plan and manifest for a refresh. Never silently overwrite an existing snapshot with different bytes.

## Acquire or resume the full MVP snapshot

The committed full plan preserves all official session spreadsheets exposed on the retrieval date plus complete paginated member and reference endpoints:

```powershell
.\.venv\Scripts\python.exe scripts/download_sources.py `
  --plan source/plans/full_swiss_2026-07-14.json `
  --manifest source/manifests/full_swiss_2026-07-14.jsonl

.\.venv\Scripts\python.exe scripts/validate_source_snapshot.py `
  --manifest source/manifests/full_swiss_2026-07-14.jsonl
```

The validated snapshot contains 362 files totaling 24,441,153 bytes, including 75 National Council and 19 Council of States XLSX workbooks. A resumptive run verifies and skips all existing successful files. Generate a newly dated plan for a refresh; never append changed bytes under this snapshot name.

## Source behavior and coverage

See:

- `documentation/ENDPOINT_INVENTORY.md`
- `documentation/COVERAGE.md`

The legacy service currently works over documented plain HTTP in this environment; HTTPS returns 403. It is read-only and receives no credentials. Official Parliament pages, access rules, and spreadsheets are retrieved over HTTPS. These transport characteristics are recorded explicitly rather than concealed.

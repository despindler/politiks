# Acquisition plans

An acquisition plan declares an immutable source snapshot without embedding download logic in notebooks.

The plan format is JSON with `version: 1` and an `items` array. A single request declares:

```json
{
  "name": "councils",
  "url": "http://ws-old.parlament.ch/councils",
  "params": {"lang": "de"},
  "path": "snapshots/fixture_2026-07-14/reference/councils.json",
  "format": "json"
}
```

Supported formats are `json`, `xml`, `pdf`, `xlsx`, and `binary`. JSON, XML, PDF, and XLSX responses are validated before the final file is created. XLSX validation checks the ZIP container and required workbook members before bytes are promoted into the snapshot.

A paginated request additionally uses:

```json
{
  "mode": "paginated",
  "page_parameter": "pageNumber",
  "first_page": 1,
  "max_pages": 2,
  "path_template": "snapshots/example/list/page_{page:06d}.json"
}
```

Omit `max_pages` for a complete traversal. Pagination stops when the last source record explicitly contains `hasMorePages: false`, when a page contains fewer than 50 records, or when a 404 follows at least one successful page. A 404 on the first page remains an error.

Plans must not contain credentials, tokens, secrets, or sensitive query parameters. Create a new snapshot directory and manifest for a refresh; do not repoint an old snapshot plan at new content.

Generate a new full Swiss spreadsheet plan from the official publication page with:

```powershell
.\.venv\Scripts\python.exe scripts/generate_full_snapshot_plan.py `
  --snapshot swiss_YYYY-MM-DD `
  --output source/plans/full_swiss_YYYY-MM-DD.json
```

The generator follows official redirects, de-duplicates final workbook URLs, requires at least the known 75 National Council and 19 Council of States files, and writes chamber-specific immutable paths. Review the generated plan before acquisition because the official page may add a newly completed session.

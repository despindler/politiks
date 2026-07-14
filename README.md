# Politiks

Politiks is an evidence-oriented application for exploring Swiss parliamentary voting records and assembling sourced insights about how parties and individual members vote. The MVP combines a reproducible SQLite research pipeline with a German-language PHP 8.4 / MariaDB web application.

The authoritative product context and implementation sequence are in:

- `.agents/CONTEXT.md`
- `.agents/CODEX.md`
- `.agents/PLAN.md`
- `PROJECT.md`

## Supported environment

- Python 3.11 or newer
- PHP 8.4
- PHP extensions: PDO MySQL, OpenSSL, cURL, and `mbstring`
- MariaDB 10.6.18 or compatible MySQL
- Node.js 22 or newer
- Apache with `.htaccess` support for deployment

The current code is developed on Windows/PowerShell, but the Python, PHP, and Playwright tooling should remain portable.

## Initial setup

Create the Python environment:

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

On macOS or Linux, activate or invoke `.venv/bin/python` instead.

Install browser-test dependencies and Chromium:

```powershell
npm.cmd install
npm.cmd run playwright:install
```

PowerShell may block the `npm.ps1` wrapper under a restrictive execution policy. `npm.cmd` avoids changing that machine-wide policy.

## Local configuration

Copy `.env.example` to `.env.test` and enter credentials for a dedicated local test database. Never use production credentials in `.env.test`.

```powershell
Copy-Item .env.example .env.test
```

Production deployment uses `site/.env` with the same variable names. All `.env*` files except `.env.example` are ignored by Git.

Check the local PHP runtime and required configuration names without exposing values:

```powershell
php scripts/check_environment.php --env=.env.test
```

The check returns a non-zero status when PHP is below 8.4, an extension is unavailable, or a required setting is absent.

## Foundation verification

Run the dependency-free PHP checks:

```powershell
php tests/php/run.php
```

Run the Playwright smoke test. Playwright starts PHP's local server for `site/` automatically:

```powershell
npm.cmd test
```

Run both:

```powershell
npm.cmd run verify
```

## Source acquisition

Acquire or safely resume the small official fixture:

```powershell
.\.venv\Scripts\python.exe scripts/download_sources.py `
  --plan source/plans/fixture.json `
  --manifest source/manifests/fixture.jsonl
```

Validate every successful manifest row against its stored byte count, SHA256, and file format:

```powershell
.\.venv\Scripts\python.exe scripts/validate_source_snapshot.py `
  --manifest source/manifests/fixture.jsonl
```

Run acquisition unit tests without relying on the Parliament service:

```powershell
.\.venv\Scripts\python.exe -m pytest
```

Observed endpoint schemas, identifiers, and coverage limitations are documented under `source/documentation/`.

## Remaining data workflow

The remaining workflow is delivered incrementally according to `.agents/PLAN.md`:

1. `database/schema.sql` and `notebooks/01_import_source_data.ipynb` will recreate the SQLite research database.
2. A later full-snapshot plan will expand the fixture after the Council of States source path is confirmed.
3. A controlled publication script will transfer the application read model into MariaDB.
4. `site/` will contain every runtime file required for Apache deployment.

Import and publication commands will be added in the milestones that implement them. They are intentionally not represented by non-functional placeholders.

## Security

Do not commit `.env`, `.env.test`, database files, upload contents, tokens, or credentials. The Google OAuth client ID is public configuration, but Google ID tokens, session identifiers, database passwords, and any OAuth client secret must never appear in source control or logs.

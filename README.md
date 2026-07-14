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

Acquire/resume and validate the full dated MVP snapshot:

```powershell
.\.venv\Scripts\python.exe scripts/download_sources.py `
  --plan source/plans/full_swiss_2026-07-14.json `
  --manifest source/manifests/full_swiss_2026-07-14.jsonl
.\.venv\Scripts\python.exe scripts/validate_source_snapshot.py `
  --manifest source/manifests/full_swiss_2026-07-14.jsonl
```

To refresh from the official spreadsheet page, first generate and review a newly dated immutable plan with `scripts/generate_full_snapshot_plan.py`; do not replace bytes inside an existing snapshot.

Run acquisition unit tests without relying on the Parliament service:

```powershell
.\.venv\Scripts\python.exe -m pytest
```

Observed endpoint schemas, identifiers, and coverage limitations are documented under `source/documentation/`.

## Research database import

Recreate `database/parliament.sqlite` from the committed fixture without network access by executing the import notebook:

```powershell
.\.venv\Scripts\python.exe -m jupyter nbconvert --to notebook --execute `
  --inplace notebooks/01_import_source_data.ipynb `
  --ExecutePreprocessor.timeout=120
```

The notebook resolves the repository root, recreates the database through `database/schema.sql`, verifies source checksums, imports in one transaction, and fails on integrity or stable-identifier duplication errors. Run it a second time to verify recreation produces identical logical counts. The generated SQLite file remains ignored.

The fixture import's supported shapes, mapping rules, counts, and limitations are in `database/IMPORT_REPORT.md`.

Recreate the full research database from committed source bytes without network access:

```powershell
$env:POLITIKS_SOURCE_MANIFEST = 'source/manifests/full_swiss_2026-07-14.jsonl'
.\.venv\Scripts\python.exe -m jupyter nbconvert --to notebook --execute `
  --inplace notebooks/01_import_source_data.ipynb `
  --ExecutePreprocessor.timeout=1200
Remove-Item Env:POLITIKS_SOURCE_MANIFEST
```

The full quality assessment, including exact historical boundaries, semantic gaps, membership gaps, duplicates, and aggregate anomalies, is in `source/documentation/DATA_QUALITY_FULL_2026-07-14.md`.

## Classification and vote search

After recreating the research database, create auditable pending suggestions and rebuild exact/full-text vote search offline:

```powershell
.\.venv\Scripts\python.exe scripts/classify_research_database.py
.\.venv\Scripts\python.exe scripts/evaluate_classification_benchmark.py
```

The taxonomy, transparent rules, controlled human-review format, optional provider-neutral model interface, queue-export command, and benchmark limitations are documented in `classification/README.md` and `classification/BENCHMARK_REPORT.md`. Automated suggestions are never exposed through the reviewed publication view until a human accepts or edits them.

## MariaDB application schema and publication

Use a dedicated database named in `.env.test`. Schema creation is idempotent; `--reset` drops every Politiks table and is therefore accepted only when the environment filename is exactly `.env.test`:

```powershell
php scripts/bootstrap_mariadb.php --env=.env.test --reset
```

Publish the currently generated SQLite read model as one immutable, atomically activated reference snapshot:

```powershell
php scripts/publish_reference_data.php `
  --env=.env.test `
  --sqlite=database/parliament.sqlite
php scripts/verify_reference_publication.php `
  --env=.env.test
```

Publication records the source snapshot/schema, source-file digest, taxonomy version/digest, reviewed-classification digest, per-table counts, and a deterministic content checksum. Repeating unchanged input reuses the existing publication. A new snapshot is populated inside one transaction and becomes visible only when every table reconciles. Pending or rejected classifications are never copied; only the reviewed projection is publishable.

The generated SQLite database can come from either the fixture or full import. Production publication should use a freshly verified full import. Bootstrap and publication are CLI tools outside `site/`; there is no HTTP installer. The deployable schema contract is documented in `site/database/README.md`.

The deployable runtime and every browser asset live entirely under `site/`; development tooling remains outside it.

## Web application and Google Sign-In

The framework-free application is served through `site/index.php`. Bootstrap 5.3.8 and Bootstrap Icons 1.13.1 are pinned in `package.json` and copied into `site/assets/vendor/`, so ordinary UI rendering has no CDN dependency. Google Identity Services is the only remotely loaded browser script and is loaded only when `GOOGLE_CLIENT_ID` is configured.

For local development, point the application at the ignored test environment and start PHP with its router:

```powershell
$env:POLITIKS_ENV_FILE = (Resolve-Path .env.test).Path
php -S 127.0.0.1:8080 -t site site/router.php
```

Production uses `site/.env`. Start from `.env.example`, set `APP_ENV=production`, use the public HTTPS URL as `APP_URL`, generate an unpredictable `APP_SECRET`, and provide the MariaDB and Google client settings. Generate a suitable application secret without printing any other configuration:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

In Google Cloud, create an OAuth 2.0 **Web application** client, add the exact production origin (scheme, host, and port when non-standard) under authorised JavaScript origins, and put its public client ID in `GOOGLE_CLIENT_ID`. No Google client secret is used by this ID-token flow. Keep the official JWKS endpoint as `GOOGLE_JWKS_URL`. Google requires its Identity Services library to be loaded from its hosted URL rather than self-hosted; the application's CSP is scoped accordingly. See the [official Google setup guide](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid).

Public authentication endpoints:

- `GET /api/auth-config` exposes only the public Google client ID or `null`.
- `GET /api/session` returns authentication state and a same-session CSRF token.
- `POST /api/google-login` verifies the Google ID token server-side and then creates, reuses, or safely links the local user.
- `POST /api/logout` destroys the authenticated session.

The landing page loads the public insight catalogue for signed-out and signed-in visitors. Signed-in users additionally manage all of their own drafts, unlisted links, and public work in “Meine Insights”. Catalogue and lifecycle endpoints are:

- `GET /api/insights/public` for the deterministic paginated public catalogue;
- `GET /api/insights/mine` for the authenticated owner's work;
- `POST /api/insights`, `PATCH /api/insights/{public-id}`, and `DELETE /api/insights/{public-id}` for CSRF-protected owner-only lifecycle changes;
- `GET /api/insights/{public-id}` for a public insight or an owner's own record; and
- `GET /api/shared-insights/{token}` plus `/geteilt/{token}` for opaque unlisted sharing with `noindex` signals.

Draft creation is intentionally allowed before parliamentary scope is complete. Every draft is still pinned immediately to the active immutable reference publication. Public publication requires a complete scope, at least one date-valid selected member, at least one selected vote with recorded participation, a title, and a claim.

## Insight wizard and vote analysis

Creating or editing an insight opens the German five-step assistant: `Rahmen`, `Mitglieder`, `Abstimmungen`, `Einordnung`, and `Prüfen`. The scope selects the country, legislature, chamber, formal party, and period. Member eligibility is the intersection of the historical formal-party membership, chamber mandate, and chosen period; faction membership is displayed separately.

Steps 2 and 3 share one member selection. Entering the vote workspace records the latest deliberate Step 2 selection as its reset baseline. Changing the cohort recalculates every vote's Yes/No/Split direction, participation denominator, cohesion, and outlier summary without discarding the search term or evidence selection. Direction always means the selected cohort's Yes-versus-No majority; abstentions, non-participation, and no mandate remain visible but do not decide direction.

Changing the parliamentary scope transactionally clears previously selected members and evidence so choices from an old chamber, party, or period cannot survive under a new frame.

Vote search covers exact affair/vote/registration identifiers and the published search document containing titles, exact questions, Yes/No meanings, official metadata, and reviewed classifications. The workspace provides direction, cohesion, vote-type, official-topic, reviewed-classification, and divergent-member filters. Selected evidence retains its immutable reference-publication and source-event identifiers. Evidence without recorded participation remains visible with a warning and blocks publication; abstention-only evidence is valid but non-directional.

Authenticated wizard endpoints are owner-only and CSRF-protected on mutation:

- `GET /insights/{public-id}/bearbeiten` renders the assistant;
- `GET /api/insights/{public-id}/wizard` returns saved state and reference options;
- `PUT /api/insights/{public-id}/scope` saves and validates the parliamentary scope;
- `GET|PUT /api/insights/{public-id}/members` reads or replaces the date-valid cohort;
- `POST /api/insights/{public-id}/votes` searches and calculates cohort results; and
- `PUT /api/insights/{public-id}/evidence` safely reorders or replaces selected votes.

The browser test fixture includes a clearly synthetic, test-only Swiss publication designed to exercise deterministic cohort changes and outliers. Production reference data continues to come exclusively from the publication pipeline.

Both mutations require `X-CSRF-Token`. Login rotates the session ID. Cookies are HTTP-only, SameSite=Lax, and secure when `APP_URL` is HTTPS. Google JWT verification requires RS256, a matching key ID/signature, the configured audience, a permitted issuer, a future expiration, and a verified unique email.

Apache must allow `.htaccess` overrides for the deployment directory. The committed rules route requests through `index.php`, disable directory listing, and hide environment files, backend PHP, database scripts, logs, and private storage. Do not deploy without those rules taking effect.

Run the full application verification (unit tests, real test-database auth integration, API/browser behavior, and visual baselines):

```powershell
npm.cmd run verify
```

Playwright's global setup seeds two deterministic test users, all three visibility states, and the synthetic vote-workspace fixture in the test database. It never reads or prints production credentials. The suite covers populated and empty catalogues, keyboard accordions, owner CRUD, unlisted-link indexing protection, the complete wizard, live cohort changes, vote inspection, validation, publication, and desktop/mobile light/dark baselines.

The production verifier needs OpenSSL and prefers cURL for Google's signing keys, with a verified HTTPS stream fallback. The local PHP CLI is currently 8.2.30 and lacks OpenSSL, cURL, and `mbstring`; production Google login therefore remains a target-host smoke check until PHP 8.4 with the required extensions is used locally.

## Security

Do not commit `.env`, `.env.test`, database files, upload contents, tokens, or credentials. The Google OAuth client ID is public configuration, but Google ID tokens, session identifiers, database passwords, and any OAuth client secret must never appear in source control or logs.

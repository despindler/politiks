# Politiks

Politiks is an evidence-oriented application for exploring Swiss parliamentary voting records and assembling sourced insights about how parties and individual members vote. The MVP combines a reproducible SQLite research pipeline with a German-language PHP 8.4 / MariaDB web application.

The authoritative product context and implementation sequence are in:

- `.agents/CONTEXT.md`
- `.agents/CODEX.md`
- `.agents/PLAN.md`
- `PROJECT.md`

Das vollständige deutschsprachige Produktionshandbuch steht in `DEPLOYMENT.md`; die finale Datenqualitäts-, Sicherheits- und Release-Prüfliste steht in `MVP_CHECKLIST.md`.

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

The notebook resolves the repository root, recreates the database through `database/schema.sql`, verifies source checksums, imports in one transaction, and fails on integrity or stable-identifier duplication errors. Run it a second time to verify recreation produces identical logical counts. The generated full SQLite file is stored through Git LFS as a deployment handoff convenience; the schema, source bytes, and notebook remain the reproducible authority.

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

The generated SQLite database can come from either the fixture or full import. Production publication should use a freshly verified full import. Bootstrap and publication are CLI tools outside `site/`; there is no HTTP installer. The MariaDB application schema contract is documented in `database/mariadb/README.md` and remains outside the public document root.

For a shared host where phpMyAdmin cannot accept the complete production dump, five consecutively importable gzip parts are provided under `database/exports/`. Select a clean target database and import `part-01-of-05.sql.gz` through `part-05-of-05.sql.gz` exactly once in numeric order. The checksums, failure-recovery rule, verification command, and regeneration command are documented in `database/exports/README.md`.

The deployable runtime and every browser asset live entirely under `site/`; development tooling remains outside it.

For an existing deployed database, apply pending forward migrations under `database/mariadb/migrations/` in filename order instead of importing the destructive full release dump. The current AI-filter upgrade is `migrate_milestones_11_14_ai_filter.sql`; it is repeatable and covered by `npm.cmd run test:migration-db`. See `DEPLOYMENT.md` for the backup, import, verification, upload, and rollback sequence.

## Web application and Google Sign-In

The framework-free application is served through `site/index.php`. Bootstrap 5.3.8 and Bootstrap Icons 1.13.1 are pinned in `package.json` and copied into `site/assets/vendor/`, so ordinary UI rendering has no CDN dependency. Google Identity Services is the only remotely loaded browser script and is loaded only when `GOOGLE_CLIENT_ID` is configured.

For local development, point the application at the ignored test environment and start PHP with its router:

```powershell
$env:POLITIKS_ENV_FILE = (Resolve-Path .env.test).Path
php -S 127.0.0.1:8080 -t site tests/support/router.php
```

Production uses `site/.env`. Start from `.env.example`, set `APP_ENV=production`, use the public HTTPS URL as `APP_URL`, generate an unpredictable `APP_SECRET`, and provide the MariaDB and Google client settings. Generate a suitable application secret without printing any other configuration:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The optional Step 3 AI filter is disabled by default (`AI_FILTER_ENABLED=0`). Its server-side Responses API boundary, versioned database prompt, strict output schema, cache, and run accounting can be prepared without an API key. Enable it only after setting `OPENAI_API_KEY` and reviewing the deployment privacy notice; the key must exist only in the uncommitted environment file. Pure PHP tests use a deterministic transport and never contact an external model. The local MariaDB foundation smoke test is:

```powershell
npm.cmd run test:ai-db
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
- `POST /api/insights` and `PATCH /api/insights/{public-id}` for CSRF-protected owner-only lifecycle changes;
- `DELETE /api/insights/{public-id}` for permanent owner-only deletion of the Insight, its selected members and evidence, campaign contexts, AI cache/run records, and uploaded campaign images;
- `GET /api/insights/{public-id}` for a public insight or an owner's own record; and
- `GET /api/shared-insights/{token}` plus `/geteilt/{token}` for opaque unlisted sharing with `noindex` signals.

Draft creation is intentionally allowed before parliamentary scope is complete. Every draft is still pinned immediately to the active immutable reference publication. Public publication requires a complete scope, at least one date-valid selected member, at least one selected vote with recorded participation, a title, and a claim.

## Insight wizard and vote analysis

Creating or editing an insight opens the German five-step assistant: `Rahmen`, `Mitglieder`, `Abstimmungen`, `Einordnung`, and `Prüfen`. The scope selects the country, legislature, chamber, formal party, and period. Member eligibility is the intersection of the historical formal-party membership, chamber mandate, and chosen period; faction membership is displayed separately.

Steps 2 and 3 share one member selection. Entering the vote workspace records the latest deliberate Step 2 selection as its reset baseline. Changing the cohort recalculates every vote's Yes/No/Split direction, participation denominator, cohesion, and outlier summary without discarding the search term or evidence selection. Direction always means the selected cohort's Yes-versus-No majority; abstentions, non-participation, and no mandate remain visible but do not decide direction.

Potentially slow wizard operations expose an accessible indeterminate activity bar, operation-specific status text, and a spinner inside the initiating button. Conflicting actions are disabled until completion, existing vote results remain visible while recalculating, and waits longer than five seconds receive an explicit follow-up message. Rapid cohort changes serialize member/evidence persistence, cancel obsolete vote requests, and render only the latest response.

Changing the parliamentary scope transactionally clears previously selected members and evidence so choices from an old chamber, party, or period cannot survive under a new frame.

Vote search covers exact affair/vote/registration identifiers and the published search document containing titles, exact questions, Yes/No meanings, official metadata, and reviewed classifications. The workspace provides direction, cohesion, vote-type, official-topic, reviewed-classification, and divergent-member filters. Selected evidence retains its immutable reference-publication and source-event identifiers. Evidence without recorded participation remains visible with a warning and blocks publication; abstention-only evidence is valid but non-directional.

Authenticated wizard endpoints are owner-only and CSRF-protected on mutation:

- `GET /insights/{public-id}/bearbeiten` renders the assistant;
- `GET /api/insights/{public-id}/wizard` returns saved state and reference options;
- `PUT /api/insights/{public-id}/scope` saves and validates the parliamentary scope;
- `GET|PUT /api/insights/{public-id}/members` reads or replaces the date-valid cohort;
- `POST /api/insights/{public-id}/votes` searches and calculates cohort results;
- `POST /api/insights/{public-id}/ai-filter` performs owner-only, CSRF-protected hybrid retrieval and structured semantic preselection for the current validated cohort; and
- `PUT /api/insights/{public-id}/evidence` safely reorders or replaces selected votes.

The AI endpoint first produces a bounded structured search plan, retrieves exact/full-text candidates inside the insight's immutable publication/scope in MariaDB, and evaluates only that compact pool in bounded chunks. It returns separate matching and ambiguous IDs with reasons and preview metadata. Selection prompt v2 explicitly distinguishes immutable candidate IDs from list positions, while each chunk's strict output schema restricts `id` to an enum of the IDs actually supplied. Server-side validation remains a final guard. The filter never selects evidence or changes the insight. Repeated identical owner/insight/publication/prompt/model/criterion/cohort requests reuse a time-limited cache; uncached requests are subject to a per-user hourly limit. Deterministic integration and HTTP tests exercise this flow without external requests:

```powershell
npm.cmd run test:ai-filter-db
npx.cmd playwright test tests/playwright/ai-filter-api.spec.js
```

When enabled, Step 3 exposes this endpoint through the optional `Mit KI eingrenzen` modal. The modal labels its output as an experimental preselection, discloses that the criterion and public parliamentary fields are processed by OpenAI, and excludes identity, campaign material, and the user's insight text. Matching and ambiguous suggestions stay editable and private in the current page session. Applying them creates only a removable vote-list filter; it does not select evidence. Closing preserves the current preview, `Verwerfen` clears it, and changing the parliamentary scope, selected cohort, or executed keyword search marks it stale until it is rerun.

The deterministic browser suite covers cancellation, long waits, focus, ambiguity, stale results, evidence isolation, and the populated/empty/error/applied states on desktop and mobile in both themes:

```powershell
npx.cmd playwright test tests/playwright/insight-wizard.spec.js --grep "AI vote filter|@visual AI"
```

The versioned German quality set in `classification/ai-filter/v1.de.json` covers clear matches, explicit exclusions, negation, empty results, missing vote semantics, plausible ambiguity, and prompt injection as data. Its offline acceptance runner is part of `npm.cmd run verify`:

```powershell
npm.cmd run test:ai-eval
```

The production feature remains disabled by default. `DATENSCHUTZ_KI.md` contains the reviewed data-flow copy, while `DEPLOYMENT.md` covers the separate OpenAI project/key, model and quota settings, safe metrics, rotation, instant disable, and the explicitly paid development-key smoke. `npm.cmd run test:ai-live` skips with zero requests unless `.env.ai-smoke` contains `OPENAI_DEVELOPMENT_API_KEY`; it never reads the production `OPENAI_API_KEY`.

The browser test fixture includes a clearly synthetic, test-only Swiss publication designed to exercise deterministic cohort changes and outliers. Production reference data continues to come exclusively from the publication pipeline.

## Campaign context

Step 4 accepts three clearly labeled user-provided context types: JPEG/PNG/WebP images, narrowly recognized YouTube watch links, and ordinary HTTP(S) links. Each item can carry a label, attribution, description, source URL, and author-controlled order. Public and shared views present this material under “Kampagnenkontext · nutzerbereitgestellt”, after the separately labeled parliamentary evidence.

Images are inspected server-side, bounded by `UPLOAD_MAX_BYTES`, stored under the HTTP-denied `site/storage/uploads/` root with generated names, and served only through an authorization-aware media endpoint. Direct storage paths, SVG, renamed scripts, invalid image bytes, oversized images, arbitrary HTML, and submitted embed markup are rejected.

YouTube embeds accept only HTTPS `youtube.com/watch?v=…` and `youtu.be/…` forms with a valid 11-character video ID. Unsupported hosts can be added only as an ordinary Weblink and are never embedded. All user text is rendered through DOM text nodes; external links use `noopener noreferrer`, and generated YouTube frames use the privacy-enhanced host and a restrictive sandbox.

Context lifecycle policy: closing the entry modal before submission stores nothing. A successful image submission attaches immediately to the draft; removing the item removes its protected file, and failed database persistence cleans up the moved file. Archiving is reversible and therefore retains context rows and files. The MVP has no hard-delete or timed-retention job.

Owner context endpoints are:

- `GET|POST /api/insights/{public-id}/contexts` for listing and link/YouTube creation;
- `POST /api/insights/{public-id}/context-images` for multipart image upload;
- `PATCH|DELETE /api/insights/{public-id}/contexts/{id}` for metadata editing and removal;
- `PUT /api/insights/{public-id}/contexts/order` for complete-list ordering; and
- `GET /media/campaign-context/{id}` for authorized image streaming, with the opaque unlisted token added only on shared views.

Both mutations require `X-CSRF-Token`. Login rotates the session ID. Cookies are HTTP-only, SameSite=Lax, and secure when `APP_URL` is HTTPS. Google JWT verification requires RS256, a matching key ID/signature, the configured audience, a permitted issuer, a future expiration, and a verified unique email.

Apache must allow `.htaccess` overrides for the deployment directory. The committed rules route requests through `index.php`, disable directory listing, hide environment files, backend PHP, database scripts, logs, and private storage, and set bounded cache headers on static assets. Production configuration requires an HTTPS `APP_URL`, emits HSTS, forbids test authentication, and suppresses error details. Do not deploy without those rules taking effect.

Run the full application verification (unit tests, real test-database auth integration, API/browser behavior, and visual baselines):

```powershell
npm.cmd run verify
```

For release acceptance, explicitly reset only the dedicated `.env.test` database, install the deterministic two-user/all-visibility fixture, and run the entire suite from that clean state:

```powershell
npm.cmd run verify:clean
```

This command is destructive only to the database named by a file whose basename is exactly `.env.test`; it requires the explicit reset flag internally. Run the deployable-boundary audit alone with `npm.cmd run test:deploy`.

Playwright's global setup seeds two deterministic test users, all three visibility states, and the synthetic vote-workspace fixture in the test database. It never reads or prints production credentials. The suite covers populated and empty catalogues, keyboard accordions, owner CRUD, unlisted-link indexing protection, the complete wizard, live cohort changes, vote inspection, campaign-context validation/presentation, publication, and desktop/mobile light/dark baselines.

The production verifier needs OpenSSL and prefers cURL for Google's signing keys, with a verified HTTPS stream fallback. The local PHP CLI is currently 8.2.30 and lacks OpenSSL, cURL, and `mbstring`; production Google login therefore remains a target-host smoke check until PHP 8.4 with the required extensions is used locally.

## Security

Do not commit `.env`, `.env.test`, database files, upload contents, tokens, or credentials. The Google OAuth client ID is public configuration, but Google ID tokens, session identifiers, database passwords, and any OAuth client secret must never appear in source control or logs.

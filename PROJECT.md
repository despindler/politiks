# Politiks project log

This file records durable milestone status, verification, decisions, and known limitations. Product requirements live in `.agents/CONTEXT.md`; planned acceptance criteria live in `.agents/PLAN.md`.

## Milestone status

| Milestone | Status | Completed |
|---|---|---|
| 0. Repository foundation and executable contracts | Complete | 2026-07-14 |
| 1. Swiss source reconnaissance and acquisition proof | Complete | 2026-07-14 |
| 2. Country-neutral SQLite research model and import | Complete | 2026-07-14 |
| 3. Full Swiss snapshot and data-quality report | Not started | — |
| 4. Auditable topic and beneficiary classification | Not started | — |
| 5. MariaDB schema and deterministic publication | Not started | — |
| 6. PHP shell, security baseline, and Google authentication | Not started | — |
| 7. Public catalogue and personal insight management | Not started | — |
| 8. Insight wizard and parliamentary evidence search | Not started | — |
| 9. Campaign-context attachments | Not started | — |
| 10. End-to-end hardening and MVP release | Not started | — |

## Working decisions

- The deployable runtime lives entirely under `site/`; research and test tooling remain outside it.
- SQLite is the reproducible research artifact. MariaDB is the deployed application database and receives reference data through a deterministic publication process.
- The MVP uses Google Sign-In only and a German user interface.
- The repository uses a small dependency-free PHP test runner initially, plus Playwright for browser behavior and visual verification.
- Local secrets use ignored `.env.test`; production secrets use ignored `site/.env`.

## Milestone 0 — Repository foundation and executable contracts

### Goal

Create a safe, reproducible starting structure with documented configuration, pinned dependencies, diagnostics, and executable smoke checks.

### Work completed

- Added the repository structure and purpose READMEs for raw sources, SQLite research data, notebooks, shared tooling, deployable site code, and tests.
- Added `.env.example` and strengthened `.gitignore` so every `.env*` variant except the example remains untracked.
- Pinned the Python research dependencies and Playwright 1.61.1, including a generated npm lock file.
- Added a dependency-free PHP test runner, a Playwright smoke test, and an initial deployable `site/index.php` foundation page.
- Added `scripts/check_environment.php`, which checks PHP 8.4, required extensions, and required environment-setting names without printing values.
- Documented local setup, verification, configuration, security boundaries, and current data-workflow status in the root README.

### Verification

- `php -l` passed for every committed PHP file.
- `php tests/php/run.php`: 3 passed, 0 failed.
- `npm.cmd test`: 1 Playwright Chromium test passed.
- Python dependency import smoke check passed inside `.venv`.
- npm installation reported zero known vulnerabilities.
- `.env.test` contained all 15 required setting names and remained ignored by Git.
- The environment diagnostic exited non-zero as designed and reported the known local runtime gaps listed below.

### Known environment limitations

- The development machine's current PHP CLI is 8.2.30 rather than the target PHP 8.4.
- Its current CLI build does not expose the cURL, OpenSSL, or `mbstring` extensions. The environment diagnostic reports these gaps explicitly.

## Milestone 1 — Swiss source reconnaissance and acquisition proof

### Goal

Confirm the official service's live behavior and create a small, auditable, resumable fixture before designing the database.

### Work completed

- Documented observed transport, content negotiation, pagination, endpoint fields, filters, identifiers, join paths, decision tokens, and discrepancies in `source/documentation/ENDPOINT_INVENTORY.md`.
- Documented official National Council and Council of States public-access periods and the unresolved machine-readable Council of States route in `source/documentation/COVERAGE.md`.
- Implemented a declarative acquisition-plan format and an official fixture plan.
- Implemented rate-limited downloads with explicit timeouts, retry/backoff, a descriptive User-Agent, safe paths, sensitive-query rejection, format validation, atomic finalization, SHA256, UTC timestamps, structured logging, and resumability.
- Implemented an append-only JSONL manifest that permits auditable error attempts but prevents duplicate successful snapshot paths.
- Implemented independent snapshot validation and a compact official fixture containing 38 responses and 1,706,073 bytes.
- Marked raw snapshot files as non-text in `.gitattributes` so Git cannot normalize official response bytes and invalidate manifest checksums.
- Preserved official web/PDF documentation, service XSDs, reference lists, current and historic member metadata, affair text, summaries, eleven individual-vote events across three affairs, and per-councillor vote examples. The vote fixture includes a clearly labeled final vote and non-final questions.
- Established that biography/CV IDs, voting councillor numbers, ELAN IDs, and individual-choice IDs are separate namespaces. Regression tests protect this distinction.

### Verification

- `python -m pytest -q`: 11 passed, including local-server tests for idempotency, relative-path handling, changed-request protection, source pagination, post-success 404 termination, transient retry, malformed JSON rejection/error recording, and official-fixture invariants.
- `python -m compileall -q src scripts tests/python`: passed.
- `scripts/validate_source_snapshot.py`: 38 files, 1,706,073 bytes, zero unresolved errors.
- A second fixture acquisition run downloaded zero files and verified/skipped all 38 existing responses without adding duplicate success rows.
- `npm.cmd run verify`: 3 PHP checks and 1 Playwright Chromium smoke test passed.

### Known limitations and next implications

- The observed `ws-old` service works through plain HTTP but returns 403 through HTTPS. Checksums protect preserved bytes after retrieval, not the network transfer.
- The sampled vote-affair events contain 199–200 choices and no chamber field, proving National Council-scale records only.
- Official rules confirm public Council of States individual votes from spring 2022 and narrower access from spring 2014, but the chamber-explicit machine-readable official route remains to be established before the full-snapshot milestone.
- The Milestone 2 schema must preserve endpoint-specific ID namespaces, raw vote tokens, unknown chamber where necessary, source provenance, and dated party/faction memberships.

## Milestone 2 — Country-neutral SQLite research model and import

### Goal

Create the reproducible research schema and import every supported local Swiss fixture response without classification or network access.

### Work completed

- Added a country-neutral normalized SQLite schema covering provenance, countries, legislatures, chambers, periods, sessions, subdivisions, committees, parties, factions, people, identifier namespaces, dated memberships, affairs, official text/topics/descriptors, voting events, aggregates, and individual choices.
- Added a transactional Swiss snapshot importer that verifies every manifest byte count and SHA256 before parsing, retains every JSON source object, and recreates the generated database from scratch.
- Kept CV person IDs, voting councillor numbers, ELAN IDs, events, registrations, and individual choices in explicit namespaces.
- Preserved raw individual decision tokens and marked the aggregate-code normalization as inferred.
- Imported current-profile party/faction intervals only with an explicit inference flag and evidence basis; explicit historic intervals remain distinguishable.
- Added an executable, documented notebook with bounded outputs for logical counts, choice counts, chamber/year coverage, unresolved links, a representative dated membership join, and final integrity assertions.
- Added importer regression tests and a fixture import report describing supported shapes and limitations.

### Verification

- `python -m pytest -q`: 14 passed, including two full clean imports with identical logical counts.
- `python -m compileall -q src scripts tests/python`: passed.
- The notebook executed top to bottom with `nbconvert` and recreated `database/parliament.sqlite` without a network request.
- Fixture results: 38 source files, 960 JSON source records, 31 normalized JSON files, 54 voting events, and 2,241 individual choices.
- `PRAGMA foreign_key_check`: zero violations; stable person and voting identifiers: zero duplicates; voting events without linked affairs: zero.

### Known limitations and next implications

- The vote source omits chamber, leaving all 54 fixture events unresolved rather than assigning them from record size.
- The bounded fixture leaves 2,169 choices without date-valid party membership and 2,158 without date-valid faction membership; full acquisition must quantify and reduce these gaps.
- Party/faction intervals derived from the three detailed current profiles are useful for representative joins but remain explicitly inferred, not source-confirmed history.
- The Council of States machine-readable acquisition route remains the principal question for Milestone 3.

# Politiks project log

This file records durable milestone status, verification, decisions, and known limitations. Product requirements live in `.agents/CONTEXT.md`; planned acceptance criteria live in `.agents/PLAN.md`.

## Milestone status

| Milestone | Status | Completed |
|---|---|---|
| 0. Repository foundation and executable contracts | Complete | 2026-07-14 |
| 1. Swiss source reconnaissance and acquisition proof | Not started | — |
| 2. Country-neutral SQLite research model and import | Not started | — |
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

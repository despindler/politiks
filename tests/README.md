# Tests

- `tests/php/` contains a small dependency-free PHP runner for foundation and backend checks.
- `tests/playwright/` contains browser behavior, accessibility-oriented interaction, and reviewed visual checks.
- `tests/support/` contains test-only helpers such as the ignored `.env.test` loader.

MariaDB integration tests must use a dedicated test database configured in `.env.test`. Tests must never use production credentials or production data.

The Milestone 5 database acceptance sequence is intentionally explicit because it resets the dedicated test schema, publishes twice, injects a transactional failure, and verifies the unchanged active snapshot. See the root README and `PROJECT.md` for the exact commands and observed results.

`npm.cmd run verify` runs pure PHP authentication/JWT/URL tests, dedicated MariaDB authentication, lifecycle, wizard, and campaign-context integrations using `.env.test`, the deployable-boundary audit, and Playwright in desktop/mobile Chromium. Browser tests load their deterministic Google verifier explicitly from `tests/support/` only when `APP_ENV=test`, `POLITIKS_TEST_AUTH=enabled`, and `POLITIKS_TEST_AUTH_BOOTSTRAP` points to that adapter; no verifier or test credential is deployed under `site/`.

`npm.cmd run verify:clean` is the release acceptance entry point. It resets only a database configured by a file named exactly `.env.test`, seeds the deterministic reference plus two users and draft/unlisted/public records, then runs the full suite. The main wizard scenario exercises the complete catalogue-to-publication path, including live cohort changes, outlier identification, evidence, campaign context, every visibility state, signed-out catalogue access, and owner editing.

Reviewed screenshots cover signed-out and signed-in shells, vote analysis, and campaign-context editing in light and dark modes at both viewports. Full-page signed-out captures cover the complete landing page; signed-in captures focus on the authenticated viewport to avoid Chromium's nondeterministic mobile full-page stitching. `visual-stability.css` affects screenshots only and makes the sticky header static during capture.

# Tests

- `tests/php/` contains a small dependency-free PHP runner for foundation and backend checks.
- `tests/playwright/` contains browser behavior, accessibility-oriented interaction, and reviewed visual checks.
- `tests/support/` contains test-only helpers such as the ignored `.env.test` loader.

MariaDB integration tests must use a dedicated test database configured in `.env.test`. Tests must never use production credentials or production data.

The Milestone 5 database acceptance sequence is intentionally explicit because it resets the dedicated test schema, publishes twice, injects a transactional failure, and verifies the unchanged active snapshot. See the root README and `PROJECT.md` for the exact commands and observed results.

`npm.cmd run verify` now runs pure PHP authentication/JWT tests, a dedicated MariaDB authentication integration using `.env.test`, and Playwright in desktop/mobile Chromium. Browser tests inject a deterministic Google verifier only when `APP_ENV=test` and `POLITIKS_TEST_AUTH=enabled`; production configuration cannot select it accidentally.

Reviewed screenshots cover signed-out and signed-in shells in light and dark modes at both viewports. Full-page signed-out captures cover the complete landing page; signed-in captures focus on the authenticated viewport to avoid Chromium's nondeterministic mobile full-page stitching. `visual-stability.css` affects screenshots only and makes the sticky header static during capture.

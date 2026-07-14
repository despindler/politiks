# Tests

- `tests/php/` contains a small dependency-free PHP runner for foundation and backend checks.
- `tests/playwright/` contains browser behavior, accessibility-oriented interaction, and reviewed visual checks.
- `tests/support/` contains test-only helpers such as the ignored `.env.test` loader.

MariaDB integration tests must use a dedicated test database configured in `.env.test`. Tests must never use production credentials or production data.

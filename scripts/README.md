# Project scripts

This directory contains explicit command-line tooling for environment checks, source acquisition, reproducible imports, publication, and deployment support.

Scripts must validate inputs, avoid printing secrets, return meaningful exit codes, and document destructive or state-changing behavior.

Current research commands include source acquisition/validation, full snapshot-plan generation, deterministic classification, benchmark evaluation, and bounded export of a human review queue. The classifier mutates only the generated ignored SQLite database; the queue exporter is read-only unless an explicit local output path is supplied.

MariaDB commands:

- `bootstrap_mariadb.php` applies `database/mariadb/schema.sql`. Its destructive `--reset` mode is guarded to `.env.test` only.
- Existing production databases use the ordered forward SQL files under `database/mariadb/migrations/`; `npm.cmd run test:migration-db` destructively simulates the pre-AI state only in `.env.test`, applies the current migration twice, and proves existing application/reference counts remain unchanged.
- `publish_reference_data.php` transactionally publishes or reuses an immutable SQLite-derived reference snapshot. Failure-injection arguments are guarded to `.env.test` and exist only for rollback verification.
- `verify_reference_publication.php` is read-only. It reconciles recorded counts, publication state, exact identifier search, and a date-valid party/member/vote join.

All three commands read credentials without echoing them and live outside the public deployment root.

Release commands:

- `audit_deployment.php` examines the versioned `site/` package, lints its PHP, and fails if runtime files are missing or test credentials, local routers, source snapshots, SQLite files, or development tooling are present.
- `verify_mvp.php --env=.env.test --reset-test-database` performs the deliberately destructive clean-test acceptance sequence and refuses any environment filename other than `.env.test`.
- `split_sql_dump.php` streams a plain or gzip SQL dump into a requested number of statement-safe `.sql.gz` files. Every part has independent session setup/cleanup so browser-based database tools can import the files consecutively in separate requests.
- `verify_split_sql_dump.php` checks every generated gzip size and checksum, decompresses all parts, validates their wrappers and byte ranges, and proves that their combined SQL payload reproduces the source checksum.
- `build_release_database_dump.php` destructively rebuilds only a `.env.test` database from a prior full gzip dump, applies the current idempotent schema, rejects application data, and creates a current gzip release dump without putting the database password on the command line.
- `evaluate_ai_vote_filter.php` scores the versioned German AI-selection cases against selection prompt v2 expectations with deterministic local results and performs no network request.
- `smoke_openai_ai_filter.php --env=.env.ai-smoke --allow-paid-api-call` performs one explicitly approved live-provider selection check with a separate development key. Add `--dataset=real` to use a bounded set of official Swiss records from `database/parliament.sqlite`, and optionally `--repeat=1..10` to measure intermittent behavior. It refuses other environment filenames and skips without a key. The CLI prefers PHP cURL and uses the non-deployable Node.js transport under `scripts/lib/` only when the local PHP build lacks cURL.

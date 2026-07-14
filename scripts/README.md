# Project scripts

This directory contains explicit command-line tooling for environment checks, source acquisition, reproducible imports, publication, and deployment support.

Scripts must validate inputs, avoid printing secrets, return meaningful exit codes, and document destructive or state-changing behavior.

Current research commands include source acquisition/validation, full snapshot-plan generation, deterministic classification, benchmark evaluation, and bounded export of a human review queue. The classifier mutates only the generated ignored SQLite database; the queue exporter is read-only unless an explicit local output path is supplied.

MariaDB commands:

- `bootstrap_mariadb.php` applies `site/database/schema.sql`. Its destructive `--reset` mode is guarded to `.env.test` only.
- `publish_reference_data.php` transactionally publishes or reuses an immutable SQLite-derived reference snapshot. Failure-injection arguments are guarded to `.env.test` and exist only for rollback verification.
- `verify_reference_publication.php` is read-only. It reconciles recorded counts, publication state, exact identifier search, and a date-valid party/member/vote join.

All three commands read credentials without echoing them and live outside the public deployment root.

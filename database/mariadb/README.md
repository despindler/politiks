# MariaDB schema contract

`schema.sql` targets MariaDB 10.6 with InnoDB and `utf8mb4`. It defines two deliberately separate domains:

- immutable, publication-scoped `ref_*` tables copied from the reproducible SQLite research model;
- application-owned users, insights, selected members, vote evidence, campaign context, and AI-filter operations.

Every insight stores the reference publication it was built against. Country, legislature, chamber, and party are nullable while a draft is incomplete. Once present, composite foreign keys ensure that scope, members, and voting evidence all belong to the same immutable publication. This preserves the evidence behind an insight when a newer parliamentary snapshot is activated.

`reference_state` is the single active-snapshot pointer. The publisher loads a complete new publication in one transaction, reconciles every table, finalizes its metadata, and changes that pointer only at commit. Unchanged deterministic input reuses its existing publication key. Application queries for current reference data must join through `reference_state`; saved insights must use their own `reference_publication_id`.

Only accepted/edited rows from SQLite's `reviewed_classification` view enter `ref_reviewed_classification`. Pending automated suggestions remain research data and are not application facts.

The insight wizard reads its scope, people, memberships, mandates, vote choices, search documents, topics, and reviewed labels from the insight's stored publication. A member is selectable only when both the formal-party membership and chamber mandate overlap the chosen period. Vote direction is an application-time cohort calculation over `yes` and `no`; abstentions and missing participation remain explicit and do not decide direction. `insight_member` and `insight_vote_evidence` store ordered source identifiers plus the same publication ID, so later reference activations cannot silently change saved evidence.

`insight_campaign_context` is application-owned and deliberately separate from `insight_vote_evidence`. It stores ordered image/YouTube/link metadata, attribution, normalized YouTube IDs, generated storage keys, MIME/size/hash metadata, and escaped user-authored descriptions. The idempotent attribution-column upgrade uses an information-schema check so both the target MariaDB 10.6 host and the local MySQL-compatible test server can apply the schema repeatedly.

`ai_prompt_template` versions the trusted developer prompts for the optional query-plan and vote-selection stages. Exactly one active template per purpose is required at runtime. `ai_filter_run` stores privacy-safe operational metadata for rate accounting and diagnostics; it deliberately stores hashes and counts rather than the user's criterion or parliamentary candidate text. `ai_filter_cache` stores the structured result behind user-, insight-, publication-, prompt-, model-, criterion/query-prompt-, candidate-, and cohort-specific hashes with an explicit expiry. None of these records makes a vote evidence item: applying an AI result remains a reversible UI filter, and evidence is still selected separately by the user.

Apply and verify from the repository root:

```powershell
php scripts/bootstrap_mariadb.php --env=.env.test
php scripts/publish_reference_data.php --env=.env.test --sqlite=database/parliament.sqlite
php scripts/verify_reference_publication.php --env=.env.test
```

`bootstrap_mariadb.php --reset` destroys the configured test schema and is accepted only for a file named `.env.test`. These CLI scripts and this schema directory are outside the deployment root.

## Standalone migrations

`migrations/migrate_milestones_11_14_ai_filter.sql` is an idempotent MariaDB 10.6 migration for upgrading an existing milestone-10 application database through milestones 11-14 and the subsequent candidate-ID reliability fix. It creates the three AI-filter application tables, preserves selection prompt v1 as retired history, and activates hardened selection prompt v2 alongside query-plan prompt v1. Back up and select the existing application database in phpMyAdmin, then paste and execute the complete script from its SQL tab.

This directory is repository tooling and is not deployed below the public `site/` document root. The application exposes no HTTP database installer or schema file.

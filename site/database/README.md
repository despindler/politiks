# MariaDB schema contract

`schema.sql` targets MariaDB 10.6 with InnoDB and `utf8mb4`. It defines two deliberately separate domains:

- immutable, publication-scoped `ref_*` tables copied from the reproducible SQLite research model;
- application-owned users, insights, selected members, vote evidence, and campaign context.

Every insight stores the reference publication it was built against. Country, legislature, chamber, and party are nullable while a draft is incomplete. Once present, composite foreign keys ensure that scope, members, and voting evidence all belong to the same immutable publication. This preserves the evidence behind an insight when a newer parliamentary snapshot is activated.

`reference_state` is the single active-snapshot pointer. The publisher loads a complete new publication in one transaction, reconciles every table, finalizes its metadata, and changes that pointer only at commit. Unchanged deterministic input reuses its existing publication key. Application queries for current reference data must join through `reference_state`; saved insights must use their own `reference_publication_id`.

Only accepted/edited rows from SQLite's `reviewed_classification` view enter `ref_reviewed_classification`. Pending automated suggestions remain research data and are not application facts.

The insight wizard reads its scope, people, memberships, mandates, vote choices, search documents, topics, and reviewed labels from the insight's stored publication. A member is selectable only when both the formal-party membership and chamber mandate overlap the chosen period. Vote direction is an application-time cohort calculation over `yes` and `no`; abstentions and missing participation remain explicit and do not decide direction. `insight_member` and `insight_vote_evidence` store ordered source identifiers plus the same publication ID, so later reference activations cannot silently change saved evidence.

`insight_campaign_context` is application-owned and deliberately separate from `insight_vote_evidence`. It stores ordered image/YouTube/link metadata, attribution, normalized YouTube IDs, generated storage keys, MIME/size/hash metadata, and escaped user-authored descriptions. The idempotent attribution-column upgrade uses an information-schema check so both the target MariaDB 10.6 host and the local MySQL-compatible test server can apply the schema repeatedly.

Apply and verify from the repository root:

```powershell
php scripts/bootstrap_mariadb.php --env=.env.test
php scripts/publish_reference_data.php --env=.env.test --sqlite=database/parliament.sqlite
php scripts/verify_reference_publication.php --env=.env.test
```

`bootstrap_mariadb.php --reset` destroys the configured test schema and is accepted only for a file named `.env.test`. These CLI scripts are outside the deployment root. Milestone 6 adds Apache rules denying direct HTTP access to this directory and other internal runtime paths.

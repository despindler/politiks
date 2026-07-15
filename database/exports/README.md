# Deployment database artifacts

The binary artifacts in this directory and `database/parliament.sqlite` are stored through Git LFS. After cloning on another computer, install Git LFS and run:

```text
git lfs install
git lfs pull
```

Artifacts for the full Swiss snapshot rebuilt against the milestone-14 application schema on 2026-07-15:

| File | Purpose | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| `../parliament.sqlite` | Reproducible research and publication source | 798752768 | `8094aae5ef23bc4da4a28943610b7ccb0e3b36e77c9b14fd2cd6f2cc2f2a1e0a` |
| `politiks-full-20260715.sql.gz` | Complete MariaDB schema and production reference publication | 37705597 | `b682add9e258a3bf973d4dc9586e62d66cac30a340139f2b27d0a13c16348b03` |

The MariaDB dump contains `DROP TABLE IF EXISTS` statements, the milestone-14 AI prompt seeds, and empty user, insight, campaign-context, AI-run, and AI-cache tables. After importing this dump, apply `database/mariadb/migrations/migrate_milestones_11_14_ai_filter.sql` once to activate the post-release selection-prompt v2 candidate-ID hardening. Back up an existing deployment before importing it. The schema, source snapshots, and import notebook remain authoritative; these binaries are deployment conveniences rather than independent sources of truth.

## Five-part phpMyAdmin import

For hosts that cannot accept the complete dump in one phpMyAdmin request, use these gzip-compressed SQL files. Each file is approximately 7.4-7.8 MB compressed and contains 71-74 MB of statement-safe SQL payload:

| Order | File | Compressed bytes | SHA-256 |
| ---: | --- | ---: | --- |
| 1 | `politiks-full-20260715-part-01-of-05.sql.gz` | 7459029 | `72051b7f3f9df7708fa2a1b60dead245741dd9c1aaa081a04538106315c87e8d` |
| 2 | `politiks-full-20260715-part-02-of-05.sql.gz` | 7592301 | `8e1d332b7d1f679320b6a34d412933ba1b5a7b6dc7734d5ad4f5ae6fcbb6ed9f` |
| 3 | `politiks-full-20260715-part-03-of-05.sql.gz` | 7565941 | `57059e1e9967b8578d3d876c11c70e77aaa2e27b274e223c7d53e8610d234e2a` |
| 4 | `politiks-full-20260715-part-04-of-05.sql.gz` | 7731349 | `899c6313918c0660786020b7447f0413d7661d1863acdaf548a22937edc47593` |
| 5 | `politiks-full-20260715-part-05-of-05.sql.gz` | 7362371 | `0ab430d515fea2703bed09ab6207a8118731d5282a46ec9df7650c8c602f9cd1` |

Select the empty target database in phpMyAdmin, open **Import**, and import parts 01 through 05 exactly once in numeric order. phpMyAdmin can import `.sql.gz` directly when gzip support is enabled. Each part establishes and restores its own character-set, time-zone, uniqueness, and foreign-key-check session settings because phpMyAdmin handles each upload in a separate request.

The split dump is destructive in the same way as the complete dump: part 01 drops existing Politiks tables, and the dump contains no application users or insights. Back up first. If a part fails, restore or recreate an empty target database and restart at part 01; do not retry a partly imported data part over the same database because its inserts are not independently idempotent.

`politiks-full-20260715-parts.json` records compressed sizes, SHA-256 values, and contiguous source byte ranges. Verify all five artifacts locally with:

```powershell
php scripts/verify_split_sql_dump.php `
  --manifest=database/exports/politiks-full-20260715-parts.json
```

To rebuild a future release dump from this verified full publication and the then-current schema, use only the disposable `.env.test` database. This command deliberately replaces that database and refuses any other environment filename:

```powershell
php scripts/build_release_database_dump.php `
  --env=.env.test `
  --input=database/exports/politiks-full-20260715.sql.gz `
  --output=database/exports/politiks-full-YYYYMMDD.sql.gz `
  --replace-test-database
```

Run `npm.cmd run verify:clean` afterwards to restore the normal deterministic test fixture. Regenerate a different part count without loading the full dump into memory:

```powershell
php scripts/split_sql_dump.php `
  --input=database/exports/politiks-full-20260715.sql.gz `
  --parts=5
```

Existing artifacts are not overwritten unless `--force` is supplied.

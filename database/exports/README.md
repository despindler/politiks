# Deployment database artifacts

The binary artifacts in this directory and `database/parliament.sqlite` are stored through Git LFS. After cloning on another computer, install Git LFS and run:

```text
git lfs install
git lfs pull
```

Artifacts for the full Swiss snapshot built on 2026-07-14:

| File | Purpose | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| `../parliament.sqlite` | Reproducible research and publication source | 798752768 | `8094aae5ef23bc4da4a28943610b7ccb0e3b36e77c9b14fd2cd6f2cc2f2a1e0a` |
| `politiks-full-20260714.sql.gz` | Complete MariaDB schema and production reference publication | 39504048 | `de9d4b8f56ef3a376a3295e862619e8cbc1d66192e1eeba8f9691a009507b6c3` |

The MariaDB dump contains `DROP TABLE IF EXISTS` statements and empty application-user and insight tables. Back up an existing deployment before importing it. The schema, source snapshots, and import notebook remain authoritative; these binaries are deployment conveniences rather than independent sources of truth.

## Five-part phpMyAdmin import

For hosts that cannot accept the complete dump in one phpMyAdmin request, use these gzip-compressed SQL files. Each file is approximately 7.4-7.8 MB compressed and contains 71-74 MB of statement-safe SQL payload:

| Order | File | Compressed bytes | SHA-256 |
| ---: | --- | ---: | --- |
| 1 | `politiks-full-20260714-part-01-of-05.sql.gz` | 7457235 | `4ac5872e450094911728646ed70e3b8c162af72675a817452488609387132ae3` |
| 2 | `politiks-full-20260714-part-02-of-05.sql.gz` | 7592101 | `1f2d346d607a2713d720d0fc99094df6541e703ca926f56e22798e6c6301a5b5` |
| 3 | `politiks-full-20260714-part-03-of-05.sql.gz` | 7565711 | `32fd1a81f310a958f01ce6e3c8e8c44da4670f73b44e99d4e0ec530a05aa48fb` |
| 4 | `politiks-full-20260714-part-04-of-05.sql.gz` | 7731152 | `be6e7f66b42263b8e570d832d853da87fc65224137b4a04232476e5ef46e34e4` |
| 5 | `politiks-full-20260714-part-05-of-05.sql.gz` | 7362099 | `509e38b219bcd976a9f8d733894d8ad471b722cb18513193d6e8c7d8f31b88d2` |

Select the empty target database in phpMyAdmin, open **Import**, and import parts 01 through 05 exactly once in numeric order. phpMyAdmin can import `.sql.gz` directly when gzip support is enabled. Each part establishes and restores its own character-set, time-zone, uniqueness, and foreign-key-check session settings because phpMyAdmin handles each upload in a separate request.

The split dump is destructive in the same way as the complete dump: part 01 drops existing Politiks tables, and the dump contains no application users or insights. Back up first. If a part fails, restore or recreate an empty target database and restart at part 01; do not retry a partly imported data part over the same database because its inserts are not independently idempotent.

`politiks-full-20260714-parts.json` records compressed sizes, SHA-256 values, and contiguous source byte ranges. Verify all five artifacts locally with:

```powershell
php scripts/verify_split_sql_dump.php `
  --manifest=database/exports/politiks-full-20260714-parts.json
```

Regenerate a different part count without loading the full dump into memory:

```powershell
php scripts/split_sql_dump.php `
  --input=database/exports/politiks-full-20260714.sql.gz `
  --parts=5
```

Existing artifacts are not overwritten unless `--force` is supplied.

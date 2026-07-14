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

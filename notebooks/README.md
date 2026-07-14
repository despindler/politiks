# Research notebooks

`01_import_source_data.ipynb` recreates the SQLite database from `database/schema.sql`, verifies and imports the local fixture responses, displays bounded coverage and choice counts, demonstrates a dated membership join, and enforces final integrity checks. It performs no network requests.

Every code cell must have a concise Markdown explanation immediately above it and bounded, informative output where appropriate.

Execute it from the repository root with:

```powershell
.\.venv\Scripts\python.exe -m jupyter nbconvert --to notebook --execute `
  --inplace notebooks/01_import_source_data.ipynb `
  --ExecutePreprocessor.timeout=120
```

That command uses the small fixture by default. Execute the full committed snapshot offline by setting its manifest and allowing time for roughly 3.86 million individual-choice rows:

```powershell
$env:POLITIKS_SOURCE_MANIFEST = 'source/manifests/full_swiss_2026-07-14.jsonl'
.\.venv\Scripts\python.exe -m jupyter nbconvert --to notebook --execute `
  --inplace notebooks/01_import_source_data.ipynb `
  --ExecutePreprocessor.timeout=1200
Remove-Item Env:POLITIKS_SOURCE_MANIFEST
```

The generated `database/parliament.sqlite` is ignored. Re-run the same command to verify identical logical counts; the notebook always recreates the file rather than incrementally mutating it.

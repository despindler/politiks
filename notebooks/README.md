# Research notebooks

`01_import_source_data.ipynb` recreates the SQLite database from `database/schema.sql`, verifies and imports the local fixture responses, displays bounded coverage and choice counts, demonstrates a dated membership join, and enforces final integrity checks. It performs no network requests.

Every code cell must have a concise Markdown explanation immediately above it and bounded, informative output where appropriate.

Execute it from the repository root with:

```powershell
.\.venv\Scripts\python.exe -m jupyter nbconvert --to notebook --execute `
  --inplace notebooks/01_import_source_data.ipynb `
  --ExecutePreprocessor.timeout=120
```

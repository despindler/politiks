# Auditable classification

This directory contains derived discovery metadata, never official parliamentary facts or user claims.

- `taxonomy/v1.de.json` is the complete versioned German vocabulary for policy topics, affected groups, mechanisms, directions, directness, and review states.
- `rules/v1.de.json` contains transparent deterministic keyword/phrase rules. A match creates a pending suggestion with confidence and a source passage.
- `reviews/v1.jsonl` is the append-only controlled human-review input.
- `benchmark/v1.de.json` contains synthetic clear, ambiguous, procedural, and mixed-effect examples.
- `ai-filter/v1.de.json` is a separate German quality set for the optional AI vote preselection, with public synthetic records and expected required, forbidden, ambiguous, and empty outcomes. It does not create reviewed political classifications.

Run the AI selection set offline without a model request:

```powershell
npm.cmd run test:ai-eval
```

## Run the offline classifier

First recreate either the fixture or full SQLite database. Then run:

```powershell
.\.venv\Scripts\python.exe scripts/classify_research_database.py
```

The command installs the taxonomy, records an immutable run configuration, creates pending deterministic suggestions, applies the controlled review file, and rebuilds exact-identifier/full-text vote search. It makes no network or model request. Running it again is idempotent for the same source snapshot and rule/taxonomy bytes.

Export a bounded pending queue with source identifiers and evidence passages:

```powershell
.\.venv\Scripts\python.exe scripts/export_classification_review_queue.py `
  --dimension affected_group --limit 100 `
  --output classification/review-queue.local.jsonl
```

Local queue exports are working files and should not be committed. Review decisions belong in the controlled `reviews/v1.jsonl` file after their reviewer identity and evidence have been checked.

## Review file

Each non-comment JSONL line identifies an immutable `suggestion_key` and a monotonically increasing revision:

```json
{"suggestion_key":"<64 hex characters>","revision":1,"decision":"accepted","reviewer":"reviewer-id","reviewed_at":"2026-07-14T12:00:00Z","notes":"Evidenz geprüft."}
```

Valid decisions are `accepted`, `rejected`, and `edited`. An edited decision must provide the complete replacement:

```json
{"suggestion_key":"<64 hex characters>","revision":2,"decision":"edited","reviewer":"reviewer-id","reviewed_at":"2026-07-14T12:30:00Z","replacement":{"dimension":"affected_group","term":"families","relationship":"beneficiary","effect_direction":"benefit","directness":"direct"},"notes":"Explizite Begünstigung im zitierten Text."}
```

Revisions are retained as history. The database view `reviewed_classification` selects only the latest accepted/edited revision; pending and rejected suggestions cannot appear through that publication surface.

## Optional model suggestions

`politiks.classification.ModelSuggestionProvider` is the provider-neutral opt-in interface. A provider supplies its name/model and returns proposals containing taxonomy code, confidence, evidence field, and evidence passage. `run_model_classification` records provider, model, prompt version, configuration, snapshot, and taxonomy checksum. It creates pending suggestions only and cannot create review rows. No concrete external provider or credential is included in the MVP pipeline.

## Search independence

`voting_event_search_document` indexes affair number, vote identifier, registration number, and date in ordinary indexed columns. `voting_event_search_fts` covers titles, exact questions, Yes/No meanings, available official text/metadata, and reviewed labels. Exact identifier lookup therefore works even when no classification exists.

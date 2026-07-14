# Shared research tooling

Reusable Python modules for acquisition, parsing, normalization, classification, and publication may live here as the data milestones are implemented.

The notebooks remain the documented top-level import workflow; helpers must not introduce hidden manual steps.

`politiks/importer.py` implements the offline, transactional Swiss fixture adapter used by the import notebook. It verifies manifest checksums, recreates SQLite from the committed schema, preserves each JSON source object, normalizes supported records, and returns the report displayed by the notebook.

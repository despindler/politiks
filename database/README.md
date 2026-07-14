# Research database

`schema.sql` is the authoritative country-neutral SQLite research schema. It separates raw provenance, country/legislature reference data, people and dated memberships, affairs and official text, and recorded vote facts. Swiss endpoint identifiers remain namespaced source mappings rather than becoming universal IDs.

`parliament.sqlite` is generated and ignored. Recreate it from committed schema, raw source files, and `notebooks/01_import_source_data.ipynb`; never treat a local binary database as the source of truth. `IMPORT_REPORT.md` documents the supported fixture shapes, row counts, mapping rules, and known limitations.

# Fixture import report

## Scope

Milestone 2 imports the verified `fixture` manifest into a generated SQLite database. The import is offline: it reads only `database/schema.sql`, `source/manifests/fixture.jsonl`, and the local files named by that manifest. The generated `database/parliament.sqlite` file is ignored because the schema, source bytes, and notebook are the reproducible authority.

The fixture run completed with:

- 38 source files registered with request and checksum provenance;
- 960 JSON source records preserved verbatim as canonical JSON;
- 31 JSON files handled by a normalized adapter;
- 54 voting events and 2,241 individual voting choices;
- zero foreign-key violations; and
- zero voting events without a linked affair record.

## Supported source shapes

The Swiss adapter currently normalizes:

- councils, legislative periods, sessions, cantons, and committees;
- current and historic parties and parliamentary factions;
- biography/CV person lists and details, historical memberships, voting-person lists, and per-person voting pages;
- affair lists, detailed affairs, official types, states, topics, descriptors, and affair summaries; and
- vote-affair lists and details, aggregate totals, raw individual decision tokens, and per-councillor vote records.

HTML, PDF, and XSD files are registered in `source_file` for provenance but are not converted into relational records. Every JSON object remains available in `source_record.raw_json`, including fields that do not yet have a normalized application use.

## Identifier and mapping rules

Swiss biography/CV person IDs, voting councillor numbers, ELAN IDs, voting-event IDs, registration numbers, and individual-choice IDs are distinct namespaces. `person_identifier` never equates their numeric values implicitly. The current fixture bridges CV IDs to voting numbers through `councillors/basicdetails` and bridges voting numbers to ELAN IDs through voting payloads.

Individual decision strings are preserved in `voting_choice.raw_decision`. The small normalized mapping is `Yes`, `No`, `EH`, `ES`, `NT`, and `P` to yes, no, abstention, non-participation, non-participation, and presiding respectively. Aggregate numeric codes are also retained; their normalized meanings are explicitly marked as inferred because the observed payload does not include a code dictionary.

## Known limitations

- All 54 imported voting events have an unresolved chamber. The sampled records contain roughly National Council-sized choice lists but no chamber field, so the importer does not silently label them as National Council records.
- 2,169 of 2,241 choices lack a date-valid party interval and 2,158 lack a date-valid faction interval in this deliberately small fixture. These records are retained.
- Historical membership records are imported as explicit when the source supplies their interval. The three detailed current profiles expose current party/faction values but not their historical intervals. Applying those current values over the profile's mandate intervals is therefore stored with `is_inferred = 1` and a descriptive `evidence_basis`.
- The fixture contains only the first pages of several large reference endpoints. Placeholder affairs and people preserve vote joins when detailed metadata is absent; the full-snapshot milestone must quantify and reduce those gaps.
- Official HTML inside affair text and summaries is preserved as source material. It is not cleaned, classified, or rendered by this milestone.
- No NLP, beneficiary classification, embeddings, or model calls occur during import.

The executable notebook reports all of these limitations and includes a representative choice-to-event-to-affair-to-person-to-date-valid-membership join. The full Swiss snapshot and broader data-quality assessment belong to Milestone 3.

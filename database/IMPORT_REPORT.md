# Fixture import report

## Scope

Milestone 2 imports the verified `fixture` manifest into a generated SQLite database. The import is offline: it reads only `database/schema.sql`, `source/manifests/fixture.jsonl`, and the local files named by that manifest. The generated `database/parliament.sqlite` is stored through Git LFS for deployment handoff, while the schema, source bytes, and notebook remain the reproducible authority.

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

# Full snapshot import report

## Scope

Milestone 3 extends the same offline importer with the official session XLSX format and the `full_swiss_2026-07-14` manifest. It supports legacy, transitional, and current workbook layouts and uses the workbook's publication path as explicit National Council or Council of States evidence.

The clean full run registers 362 source files and 34,646 source records. It imports 21,569 unique voting events and 3,861,590 individual choices, resolves all event chambers and people, and finishes with zero foreign-key violations. The complete metrics and source anomalies are in `source/documentation/DATA_QUALITY_FULL_2026-07-14.md`.

## XLSX identifier and mapping rules

The event stable key is the explicit chamber plus official reference number. Official affair numbers are canonicalized without discarding their displayed form. The physical workbook name, sheet, row number, exact question/submission, Yes/No meanings, raw member decision, member column, aggregates, overall decision, and type-derivation basis retain source provenance.

Person columns can supply a voting councillor number, biography/CV ID, or a name/faction/canton identity depending on workbook age. Identifiers are namespaced and bridged only when an official record explicitly supplies both values. Historic councillor records and daily workbook faction evidence resolve names without equating unrelated numeric namespaces.

Individual choices normalize German/English Yes and No, abstention, excused, non-participation, and presiding tokens. Unknown tokens remain `other`. Empty member cells remain without an inferred individual choice because some workbook member headers contain people not active for that vote. Aggregate labels such as `Anzahl 'Ja'` use a separate descriptive-header normalizer and unknown aggregate metadata is ignored.

Vote types `final`, `overall`, `entry`, and `urgency` are derived deterministically from official question phrases. Every derived value stores `derived_from_official_question_text`; unmatched rows store `other` with `unclassified_official_question_text`. This is a discovery aid, not an assertion that the Parliament supplied that category.

## Layout repairs and duplicates

Two narrow structural repairs are tested and source-visible:

- one official 2015 legacy workbook splits a long vote across two physical spreadsheet rows; the importer reconstructs the row while preserving its original row provenance; and
- one current-layout row spills a long question across metadata columns; the aggregate block is detected from its numeric decision/count structure and the text is reconstructed.

Fourteen physical source rows repeat a chamber/reference-number event already present elsewhere in the official workbook set. All 21,583 physical rows are retained in `source_record`; normalized event and choice uniqueness produces 21,569 events. No duplicate stable identifiers remain in normalized tables.

## Reproduction

The notebook defaults to the small fixture. Set `POLITIKS_SOURCE_MANIFEST=source/manifests/full_swiss_2026-07-14.jsonl` before executing it to recreate the full database. It performs no network requests and validates every manifest byte count and SHA256 first. The generated full SQLite artifact is stored through Git LFS for deployment handoff.

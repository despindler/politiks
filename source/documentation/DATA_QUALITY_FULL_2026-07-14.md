# Full Swiss snapshot data-quality report

Snapshot: `swiss_2026-07-14`

Assessment date: 2026-07-14

Manifest: `source/manifests/full_swiss_2026-07-14.jsonl`

## Scope and provenance

The immutable snapshot contains 362 validated files totaling 24,441,153 bytes. Every successful manifest entry matches its stored byte count and SHA256, and the validator reports zero unresolved acquisition errors. The principal vote source is the [official Parliament session-spreadsheet page](https://www.parlament.ch/de/ratsbetrieb/abstimmungen/abstimmung-nr-xls), preserved together with the official voting-data access rules.

The snapshot includes 75 National Council workbooks and 19 Council of States workbooks. It also includes complete paginated reference lists for councils, periods, sessions, cantons, committees, factions, parties, councillors, historic memberships, and voting-person identifiers. The legacy JSON service was used only for reference entities; the chamber-explicit XLSX files are the authority for the full roll calls.

| Chamber | Workbook period | Workbooks | Unique events | Individual choices |
|---|---|---:|---:|---:|
| National Council | 2011-12-05 to 2026-06-19 | 75 | 18,647 | 3,727,741 |
| Council of States | 2022-02-28 to 2026-06-19 | 19 | 2,922 | 133,849 |
| Total | 2011-12-05 to 2026-06-19 | 94 | 21,569 | 3,861,590 |

The importer preserves 21,583 physical workbook event rows. Fourteen repeated normalized event identifiers occur in the official workbooks; all physical rows remain in `source_record`, while `voting_event` contains 21,569 unique chamber/reference-number events. The generated SQLite database is not committed.

## Normalized result coverage

| Recorded choice | Rows |
|---|---:|
| Yes | 2,239,453 |
| No | 1,310,702 |
| Abstention | 64,485 |
| Excused | 52,981 |
| Did not participate | 173,534 |
| Presiding | 20,096 |
| Other | 339 |

Of the 339 retained `other` decisions, 338 are the literal official token `unknown` and one is `anwesend`. They are intentionally not forced into Yes, No, or abstention.

The database contains 3,898 people and 4,760 namespaced identifiers. Every imported individual choice resolves to a person; no placeholder person or unresolved event chamber remains. `PRAGMA foreign_key_check` returns zero rows.

## Vote types and semantics

Vote type is a deterministic, provenance-labeled derivation from official question text, not an official classification supplied by the workbooks.

| Chamber | Entry | Final | Overall | Urgency | Other/unclassified |
|---|---:|---:|---:|---:|---:|
| National Council | 525 | 918 | 1,590 | 36 | 15,578 |
| Council of States | 109 | 296 | 494 | 12 | 2,011 |

| Chamber | Missing Yes meaning | Missing No meaning | Missing exact question | Event without affair link |
|---|---:|---:|---:|---:|
| National Council | 444 | 446 | 4,544 | 15 |
| Council of States | 0 | 0 | 0 | 2 |

The 17 events without an affair link are retained as procedural/unassigned roll calls with their official reference number and workbook provenance. The older National Council layouts do not publish all semantic fields on every row; missing values remain null and are never synthesized.

The snapshot creates 7,171 affair records from workbook identifiers, titles, and types, but it does not yet acquire every per-affair detail/summary document: `matter_text`, `matter_summary`, `matter_topic`, and `matter_descriptor` are empty in this build. Exact vote question, submission, and Yes/No meaning remain searchable where the workbook supplies them. Full affair-text enrichment is a quantified input gap for classification and richer search, not a hidden claim of completeness.

## Aggregate reconciliation

Official aggregate counts are stored independently from the individual member matrix. Across 21,569 events, 21,390 reconcile exactly for every comparable published decision count. The remaining 179 events occur in five National Council workbooks and contain 270 mismatched aggregate cells with an absolute difference of 4,065 choices:

| Workbook | Affected events | Observed issue |
|---|---:|---|
| `4913-2014-sondersession-mai-d.xlsx` | 31 | Aggregate/member-matrix differences, including ambiguous blank member cells |
| `4914-2014-sommersession-d.xlsx` | 116 | Aggregate/member-matrix differences, including ambiguous blank member cells |
| `4916-2014-wintersession-d.xlsx` | 13 | Aggregate/member-matrix differences, including ambiguous blank member cells |
| `Abstimmungen_NR_2023FS_DE.xlsx` | 13 | Published aggregate cells are doubled for the affected rows |
| `Abstimmungen_NR_2023HS_DE.xlsx` | 6 | Published aggregate cells are doubled for the affected rows |

An empty member cell is not converted into an individual decision because the 2014 metadata also contains members who were not yet active; the aggregate does not identify which blank person it counts. Likewise, doubled 2023 aggregates are preserved rather than silently divided. The application must prefer explicit individual choices for cohort analysis and may display the official aggregate with a source-quality warning on these events.

## Dated membership coverage

The import contains 7,502 dated party-membership intervals and 175,724 faction-membership intervals. Session spreadsheets provide explicit faction-at-vote-date evidence for 168,515 intervals; historic reference records provide the remainder.

- 39,445 of 3,861,590 choices (1.02%) have no date-valid party interval: 38,187 National Council and 1,258 Council of States choices.
- 120 choices (0.003%) have no date-valid faction interval.

These choices remain available and are labeled as lacking dated membership evidence. Current party and faction values are never silently projected backward over a historical vote.

## Historical and language gaps

- Complete National Council spreadsheet coverage starts in winter 2011. Official public-access rules cover all National Council votes since winter 2003 and a narrower set from 1996; those earlier records need a separate official adapter.
- Complete Council of States spreadsheets start in spring 2022. The narrower 2014â€“2021 protocol scope requires Official Bulletin extraction and is not in this snapshot.
- German was requested from the legacy service and German workbook links were selected, but individual official fields can still contain French or Italian text.
- Legacy reference JSON travels over the source's observed HTTP-only endpoint. HTTPS is used for Parliament pages, PDFs, and XLSX files. Checksums secure preserved bytes after retrieval, not the legacy HTTP transport itself.
- The snapshot is time-bound. Later corrections or newly published sessions require a new plan, directory, and manifest rather than changing these bytes.

## Reproduction result

Two independent clean imports from this manifest produced the same logical table and choice counts. The full notebook build validates source bytes before import, reports bounded coverage, enforces stable-identifier uniqueness, and finishes with zero foreign-key violations. Reproduction commands are documented in the root README and `notebooks/README.md`.

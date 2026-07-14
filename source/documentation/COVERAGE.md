# Voting-data coverage assessment

Assessment date: 2026-07-14.

## Official public-access rules

The official October 2024 document `zugang-abstimmungsdaten.pdf` states that individual voting behavior is publicly accessible for:

### National Council

- all votes since the winter session of 2003; and
- from winter session 1996 through autumn session 2003, overall votes, final votes, urgency votes, and votes requested by at least 30 members.

### Council of States

- all votes since the spring session of 2022; and
- from spring session 2014 through winter session 2021, overall votes, final votes, votes requiring a majority of all members under Federal Constitution article 159(3), and votes requested by at least ten members.

The same document says available formats are listed on the Parliament website. The public voting page links a National Council voting database and session Excel tables, while referring voting protocols generally to the Official Bulletin.

## What the fixture proves

- `/councillors/basicdetails` contains exactly 200 current `N` records and 46 current `S` records in the snapshot, so member and party/faction metadata cover both chambers.
- The first historic-councillor page includes 41 `N`, 7 `S`, and 2 `B` council membership records, demonstrating nested dated memberships for multiple council types.
- The documented `ws-old` vote-affair details sampled here contain 199–200 individual choices per event and no chamber field. They demonstrate National Council-scale roll calls only.
- A current Council of States member can have records under `/votes/councillors/<NUMBER>` from an earlier National Council mandate. A person's current council must therefore never be used to label the chamber of historical vote records.
- The fixture contains eleven vote events across three affairs, with exact Yes/No meanings and individual choices. It includes majority/minority deadline or write-off decisions, multilingual vote meaning text, non-final questions, and a confirmed 2020-12-18 `Schlussabstimmung` whose meanings are `Annahme der Vorlage` and `Ablehnung der Vorlage`.

## Unresolved Council of States acquisition path

Public Council of States individual votes exist under the official access rules, but this reconnaissance did not establish a chamber-explicit, machine-readable Council of States payload in the documented `ws-old` endpoints. The Official Bulletin is the official fallback reference, but its protocol extraction path has not yet been implemented or validated.

Consequences:

- Do not claim that the fixture contains Council of States roll-call votes.
- Do not infer a vote's chamber from a person's current council.
- Keep the research schema capable of both chambers and of source-specific chamber evidence.
- Before the full-snapshot milestone, investigate the official bulletin/download format for Council of States voting protocols and document its join identifiers and coverage.
- If no reliable official machine-readable route is available, pause before introducing a non-official secondary source or HTML extraction because that changes the source policy and requires an explicit project decision.

## Other limitations

- The two `/affairs` fixture pages are a pagination proof, not a historical census.
- Reference and historic lists are sampled unless their endpoint is documented and observed as non-paginated.
- `lang=de` does not guarantee that every substantive vote field is German.
- Source `updated` timestamps show that old affairs and vote groupings continue to change; analyses must identify their retrieval snapshot.
- The HTTP-only `ws-old` endpoint lacks TLS integrity in the observed environment. Local checksums protect the preserved snapshot after download, not the network transfer itself.

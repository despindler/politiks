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

## Resolved chamber-explicit spreadsheet path

The official Parliament page [`abstimmung-nr-xls`](https://www.parlament.ch/de/ratsbetrieb/abstimmungen/abstimmung-nr-xls) exposes session XLSX files for both chambers. On 2026-07-14 it linked 75 National Council workbooks from winter session 2011 through summer session 2026 and 19 Council of States workbooks from spring session 2022 through summer session 2026. The full snapshot preserves that page, every linked workbook, and the official access-rules PDF.

The spreadsheets explicitly identify their chamber through the publication/link and contain event date and reference number, affair identifiers where applicable, question/submission text, Yes/No meanings where published, aggregate counts, and one decision column per member. They therefore replace the earlier temptation to infer a chamber from a roughly 200-person payload. Three historical workbook layouts are supported and regression-tested.

Consequences:

- The small fixture remains a valid service-shape proof but must not be described as Council of States roll-call coverage.
- The full snapshot contains explicit Council of States individual votes from spring 2022, matching the start of complete public access in the official rules.
- National Council spreadsheet coverage begins in winter 2011 even though other official access routes cover earlier votes. National Council 2003â€“2011 and the narrower 1996â€“2003 scope remain future acquisition work.
- Council of States 2014â€“2021 voting protocols remain available under the narrower official rules but are outside the current machine-readable spreadsheet snapshot.
- A person's current chamber is never used to label a historical event; workbook provenance supplies the chamber.

## Other limitations

- The two `/affairs` fixture pages are a pagination proof, not a historical census.
- Reference and historic lists are sampled unless their endpoint is documented and observed as non-paginated.
- `lang=de` does not guarantee that every substantive vote field is German.
- Source `updated` timestamps show that old affairs and vote groupings continue to change; analyses must identify their retrieval snapshot.
- The HTTP-only `ws-old` endpoint lacks TLS integrity in the observed environment. Local checksums protect the preserved snapshot after download, not the network transfer itself.

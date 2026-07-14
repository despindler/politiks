# Swiss Parliament web-service reconnaissance

Observed on 2026-07-14 against the official sources preserved in `source/snapshots/fixture_2026-07-14/`.

## Authoritative documentation

- Official open-data page: `documentation/open-data-web-services.html`
- Short web-service documentation: `documentation/kurzdokumentation-webservices-d.pdf`
- Parliamentary-data rules: `documentation/rahmenregeln-parlamentsdaten.pdf`
- Voting-data access rules: `documentation/zugang-abstimmungsdaten.pdf`
- Service-supplied XSD examples: `schemas/`

Attribution required by the official open-data page:

`Parlamentsdienste der Bundesversammlung, Bern`

The official page requires the source and retrieval date to remain evident and warns that downstream use must not appear to be an official publication.

## Transport and representation behavior

- The documented service base is `http://ws-old.parlament.ch`.
- Plain HTTP returned the requested data during reconnaissance. Equivalent HTTPS requests returned HTTP 403, so the acquisition plan currently uses HTTP for this public, read-only, non-credentialed service.
- The official documentation says `format=json` or the HTTP `Accept` header can select JSON. In observed requests, `Accept: application/json` was the reliable selector; the downloader always sends it for JSON.
- HTML is intended only for human inspection and omits source fields. It is never parsed as data.
- `lang=de` is the default project request. Individual source values can still be French, as demonstrated by the 2026 vote meanings in affair `20150320`; language cannot be inferred from the request alone.
- List and detail records expose `updated` timestamps. A raw dated snapshot is necessary because the service is mutable.

The HTTP-only behavior is a transport-integrity limitation. Checksums prove local snapshot integrity after retrieval but cannot provide TLS authenticity for `ws-old`. Official PDFs and web pages are retrieved over HTTPS.

## Pagination behavior

- The documented parameter is `pageNumber`, starting at 1, with at most 50 entries per page. Parameter casing was accepted during reconnaissance; the unrelated `page` parameter was ignored.
- The documentation describes `hasMorePages` on the final record. This flag is present on some observed lists, including historic councillors and per-councillor votes, but absent from the sampled `/affairs` JSON pages.
- Requesting a page well beyond `/affairs` returned HTTP 404, consistent with the documented empty-result behavior.
- The downloader therefore prefers an explicit `hasMorePages`, treats a short page as terminal, and otherwise continues until a post-success 404. An initial 404 is fatal.
- The fixture deliberately limits `/affairs` to two pages and does not imply completeness.

## Observed endpoint shapes

Fields below are top-level fields observed in the committed fixture, not an invented target schema.

| Endpoint | Fixture count | Observed top-level fields |
|---|---:|---|
| `/councils` | 3 | `id`, `updated`, `abbreviation`, `code`, `name`, `type` |
| `/LegislativePeriods` | 16 | `id`, `updated`, `code`, `from`, `name`, `to` |
| `/sessions` page 1 | 50 | `id`, `updated`, `code`, `from`, `name`, `to` |
| `/cantons` | 26 | `id`, `updated`, `abbreviation`, `code`, `name` |
| `/committees` page 1 | 50 | `id`, `updated`, `abbreviation`, `code`, `committeeNumber`, `council`, `from`, `isActive`, `name`, `typeCode` |
| `/Factions` | 7 | `id`, `updated`, `abbreviation`, `code`, `name`, `shortName` |
| `/Factions/historic` page 1 | 50 | `id`, `updated`, `abbreviation`, `code`, `from`, `name`, `shortName`, `to` |
| `/Parties/historic` page 1 | 50 | `id`, `updated`, `abbreviation`, `code`, `name` |
| `/councillors` page 1 | 50 | `id`, `updated`, `active`, `code`, names and salutations |
| `/councillors/basicdetails` | 246 | CV `id`, voting `number`, council, party, faction, canton, names, URLs |
| `/councillors/historic` page 1 | 50 | person, nested council/canton/party/faction, nested dated `membership`, biographical masks |
| `/councillors/<CV_ID>` | one object | biography, concerns, professions, committee memberships, council memberships |
| `/affairs` | 50/page | `id`, `updated`, `shortId`; sampled JSON omits `hasMorePages` |
| `/affairs/types` | 15 | `id`, `updated`, `abbreviation`, `code`, `name` |
| `/affairs/states` | 47 | `id`, `updated`, `code`, `name`, `sorting` |
| `/affairs/topics` | 21 | `id`, `updated`, `code`, `name` |
| `/affairs/descriptors` | 18 | `id`, `updated`, `code`, `name` |
| `/affairs/<ID>` | one object | indexing, type, author, deposit, descriptors, drafts, language, councils, relations, roles, state, texts, title |
| `/affairsummaries` page 1 | 50 | `id`, `updated`, `formattedId`, `title` |
| `/affairsummaries/<ID>` | one object | description, initial situation, deliberation, chronology and title fields when available |
| `/votes/affairs` page 1 | 50 | affair `id`, `updated`, `title` |
| `/votes/affairs/<AFFAIR_ID>` | one object | affair `id`, `updated`, `title`, nested `affairVotes` |
| `/votes/councillors` page 1 | 50 | voting councillor `id`, `updated`, `elanId`, names |
| `/votes/councillors/<NUMBER>` | one object | voting councillor identity and paginated `affairVotes` with one nested `councillorVote` each |

The official short documentation says the vote-councillor detail route is `/votes/councilors/<ID>` with one `l`. That spelling returned 404 in observed requests; `/votes/councillors/<NUMBER>` with two `l`s returned JSON. The acquisition plan and code use the observed working route and retain this discrepancy in the manifest notes.

## Identifiers and join path

Do not collapse the service identifiers into one person ID.

The current `basicdetails` record for Marianne Binder-Keller demonstrates:

- biography/CV `id`: `4249`;
- voting councillor `number`: `3141`;
- voting-system `elanId`: `921` in the vote service;
- current council: `S`.

`/councillors/4249` returns her biography, while `/votes/councillors/3141` returns her paginated voting records. Calling `/councillors/3141` resolves to a different historical biography. Thomas Aeschi similarly uses CV `id=4053` and voting `number=2758`; Jacqueline Badran uses CV `id=4058` and voting `number=2762`.

The practical join is:

```text
councillors/basicdetails.id       -> /councillors/<CV_ID>
councillors/basicdetails.number   -> /votes/councillors/<NUMBER>
individual councillorVote.number  -> basicdetails.number
individual councillorVote.elanId  -> votes/councillors.elanId
votes/affairs.id                  -> affairs.id
```

Keep every identifier with an explicit namespace and source endpoint.

## Vote detail observations

The fixture contains eleven voting events across affairs `20120409`, `20150320`, and `20130468`. Affair `20130468` includes a clearly labeled `Schlussabstimmung` as well as non-final questions. Each event has:

- `id`, `date`, `registrationNumber`;
- `divisionText`, `submissionText`;
- `meaningYes`, `meaningNo`;
- `totalVotes`, `filteredTotalVotes`; and
- nested `councillorVotes` containing choice `id`, voting `number`, `elanId`, names, and `decision`.

Observed decision tokens are `Yes`, `No`, `EH`, `ES`, `NT`, and `P`. Matching the individual-token counts to the aggregate numeric `type` counts across the fixture events strongly indicates:

| Decision token | Aggregate type | Working interpretation |
|---|---:|---|
| `Yes` | 1 | Yes |
| `No` | 2 | No |
| `EH` | 3 | Abstention (`Enthaltung`) |
| `NT` | 5 | Did not participate |
| `ES` | 6 | Excused |
| `P` | 7 | Presiding member |

This mapping is an inference from exact count reconciliation, not a label supplied by the sampled JSON or XSD. Preserve raw tokens and numeric types and mark the mapping provenance until an official code list confirms it.

The voting-event payload does not contain a chamber field. The fixture events contain 199–200 member choices and therefore exhibit National Council scale, but chamber must not be inferred from a generic count alone in the normalized schema.

## Filters observed in the service HTML

The service advertises these `/votes/affairs` filters: `dateFromFilter`, `dateToFilter`, `legislativePeriodFilter`, `sessionFilter`, `cantonFilter`, `councillorNumberFilter`, `factionFilter`, `decisionsFilter`, `searchTextFilter`, and `affairNumberFilter`.

The fixture uses `dateFromFilter=2025/01/01`. That filter selects affairs having relevant votes after the date; it does not mean the affair itself was introduced after that date.

## Schema implications for Milestone 2

- Preserve raw service identifiers with endpoint-specific namespaces.
- Store party and faction separately and resolve dated memberships from historic membership records rather than current `basicdetails` alone.
- Preserve every text field and its supplied language or role without destructive cleanup.
- Store raw decision tokens and aggregate numeric types even when a normalized working mapping is available.
- Represent chamber explicitly but allow it to be unknown until supported by source evidence.
- Keep source-file provenance on every imported record.

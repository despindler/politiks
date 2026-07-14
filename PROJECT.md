# Politiks project log

This file records durable milestone status, verification, decisions, and known limitations. Product requirements live in `.agents/CONTEXT.md`; planned acceptance criteria live in `.agents/PLAN.md`.

## Milestone status

| Milestone | Status | Completed |
|---|---|---|
| 0. Repository foundation and executable contracts | Complete | 2026-07-14 |
| 1. Swiss source reconnaissance and acquisition proof | Complete | 2026-07-14 |
| 2. Country-neutral SQLite research model and import | Complete | 2026-07-14 |
| 3. Full Swiss snapshot and data-quality report | Complete | 2026-07-14 |
| 4. Auditable topic and beneficiary classification | Complete | 2026-07-14 |
| 5. MariaDB schema and deterministic publication | Complete | 2026-07-14 |
| 6. PHP shell, security baseline, and Google authentication | Complete | 2026-07-14 |
| 7. Public catalogue and personal insight management | Complete | 2026-07-14 |
| 8. Insight wizard and parliamentary evidence search | Complete | 2026-07-14 |
| 9. Campaign-context attachments | Not started | — |
| 10. End-to-end hardening and MVP release | Not started | — |

## Working decisions

- The deployable runtime lives entirely under `site/`; research and test tooling remain outside it.
- SQLite is the reproducible research artifact. MariaDB is the deployed application database and receives reference data through a deterministic publication process.
- The MVP uses Google Sign-In only and a German user interface.
- The repository uses a small dependency-free PHP test runner initially, plus Playwright for browser behavior and visual verification.
- Local secrets use ignored `.env.test`; production secrets use ignored `site/.env`.

## Milestone 0 — Repository foundation and executable contracts

### Goal

Create a safe, reproducible starting structure with documented configuration, pinned dependencies, diagnostics, and executable smoke checks.

### Work completed

- Added the repository structure and purpose READMEs for raw sources, SQLite research data, notebooks, shared tooling, deployable site code, and tests.
- Added `.env.example` and strengthened `.gitignore` so every `.env*` variant except the example remains untracked.
- Pinned the Python research dependencies and Playwright 1.61.1, including a generated npm lock file.
- Added a dependency-free PHP test runner, a Playwright smoke test, and an initial deployable `site/index.php` foundation page.
- Added `scripts/check_environment.php`, which checks PHP 8.4, required extensions, and required environment-setting names without printing values.
- Documented local setup, verification, configuration, security boundaries, and current data-workflow status in the root README.

### Verification

- `php -l` passed for every committed PHP file.
- `php tests/php/run.php`: 3 passed, 0 failed.
- `npm.cmd test`: 1 Playwright Chromium test passed.
- Python dependency import smoke check passed inside `.venv`.
- npm installation reported zero known vulnerabilities.
- `.env.test` contained all 15 required setting names and remained ignored by Git.
- The environment diagnostic exited non-zero as designed and reported the known local runtime gaps listed below.

### Known environment limitations

- The development machine's current PHP CLI is 8.2.30 rather than the target PHP 8.4.
- Its current CLI build does not expose the cURL, OpenSSL, or `mbstring` extensions. The environment diagnostic reports these gaps explicitly.

## Milestone 1 — Swiss source reconnaissance and acquisition proof

### Goal

Confirm the official service's live behavior and create a small, auditable, resumable fixture before designing the database.

### Work completed

- Documented observed transport, content negotiation, pagination, endpoint fields, filters, identifiers, join paths, decision tokens, and discrepancies in `source/documentation/ENDPOINT_INVENTORY.md`.
- Documented official National Council and Council of States public-access periods and the unresolved machine-readable Council of States route in `source/documentation/COVERAGE.md`.
- Implemented a declarative acquisition-plan format and an official fixture plan.
- Implemented rate-limited downloads with explicit timeouts, retry/backoff, a descriptive User-Agent, safe paths, sensitive-query rejection, format validation, atomic finalization, SHA256, UTC timestamps, structured logging, and resumability.
- Implemented an append-only JSONL manifest that permits auditable error attempts but prevents duplicate successful snapshot paths.
- Implemented independent snapshot validation and a compact official fixture containing 38 responses and 1,706,073 bytes.
- Marked raw snapshot files as non-text in `.gitattributes` so Git cannot normalize official response bytes and invalidate manifest checksums.
- Preserved official web/PDF documentation, service XSDs, reference lists, current and historic member metadata, affair text, summaries, eleven individual-vote events across three affairs, and per-councillor vote examples. The vote fixture includes a clearly labeled final vote and non-final questions.
- Established that biography/CV IDs, voting councillor numbers, ELAN IDs, and individual-choice IDs are separate namespaces. Regression tests protect this distinction.

### Verification

- `python -m pytest -q`: 11 passed, including local-server tests for idempotency, relative-path handling, changed-request protection, source pagination, post-success 404 termination, transient retry, malformed JSON rejection/error recording, and official-fixture invariants.
- `python -m compileall -q src scripts tests/python`: passed.
- `scripts/validate_source_snapshot.py`: 38 files, 1,706,073 bytes, zero unresolved errors.
- A second fixture acquisition run downloaded zero files and verified/skipped all 38 existing responses without adding duplicate success rows.
- `npm.cmd run verify`: 3 PHP checks and 1 Playwright Chromium smoke test passed.

### Known limitations and next implications

- The observed `ws-old` service works through plain HTTP but returns 403 through HTTPS. Checksums protect preserved bytes after retrieval, not the network transfer.
- The sampled vote-affair events contain 199–200 choices and no chamber field, proving National Council-scale records only.
- Official rules confirm public Council of States individual votes from spring 2022 and narrower access from spring 2014, but the chamber-explicit machine-readable official route remains to be established before the full-snapshot milestone.
- The Milestone 2 schema must preserve endpoint-specific ID namespaces, raw vote tokens, unknown chamber where necessary, source provenance, and dated party/faction memberships.

## Milestone 2 — Country-neutral SQLite research model and import

### Goal

Create the reproducible research schema and import every supported local Swiss fixture response without classification or network access.

### Work completed

- Added a country-neutral normalized SQLite schema covering provenance, countries, legislatures, chambers, periods, sessions, subdivisions, committees, parties, factions, people, identifier namespaces, dated memberships, affairs, official text/topics/descriptors, voting events, aggregates, and individual choices.
- Added a transactional Swiss snapshot importer that verifies every manifest byte count and SHA256 before parsing, retains every JSON source object, and recreates the generated database from scratch.
- Kept CV person IDs, voting councillor numbers, ELAN IDs, events, registrations, and individual choices in explicit namespaces.
- Preserved raw individual decision tokens and marked the aggregate-code normalization as inferred.
- Imported current-profile party/faction intervals only with an explicit inference flag and evidence basis; explicit historic intervals remain distinguishable.
- Added an executable, documented notebook with bounded outputs for logical counts, choice counts, chamber/year coverage, unresolved links, a representative dated membership join, and final integrity assertions.
- Added importer regression tests and a fixture import report describing supported shapes and limitations.

### Verification

- `python -m pytest -q`: 14 passed, including two full clean imports with identical logical counts.
- `python -m compileall -q src scripts tests/python`: passed.
- The notebook executed top to bottom with `nbconvert` and recreated `database/parliament.sqlite` without a network request.
- Fixture results: 38 source files, 960 JSON source records, 31 normalized JSON files, 54 voting events, and 2,241 individual choices.
- `PRAGMA foreign_key_check`: zero violations; stable person and voting identifiers: zero duplicates; voting events without linked affairs: zero.

### Known limitations and next implications

- The vote source omits chamber, leaving all 54 fixture events unresolved rather than assigning them from record size.
- The bounded fixture leaves 2,169 choices without date-valid party membership and 2,158 without date-valid faction membership; full acquisition must quantify and reduce these gaps.
- Party/faction intervals derived from the three detailed current profiles are useful for representative joins but remain explicitly inferred, not source-confirmed history.
- The Council of States machine-readable acquisition route remains the principal question for Milestone 3.

## Milestone 3 — Full Swiss snapshot and data-quality report

### Goal

Acquire and reproducibly import the practical chamber-explicit historical scope required for the Swiss MVP, then quantify every material coverage and source-quality gap.

### Work completed

- Resolved the official Council of States source path through the Parliament's session-spreadsheet page, avoiding a non-official source or chamber inference.
- Added a reproducible plan generator and committed the dated `swiss_2026-07-14` plan, manifest, official documentation, complete reference pages, and all 94 linked session XLSX workbooks.
- Extended acquisition validation to inspect XLSX ZIP/workbook structure before atomic promotion and added regression coverage for malformed spreadsheets.
- Added a streaming parser for legacy, transitional, and current workbook layouts, including narrow provenance-preserving repairs for two observed official structural defects.
- Extended the country-neutral import with explicit workbook chamber evidence, exact vote semantics, overall decisions, type-derivation provenance, official aggregates, member choices, namespaced identifiers, and explicit faction-at-vote-date evidence.
- Kept 21,583 physical workbook event rows in provenance while normalizing 14 repeated event keys to 21,569 unique events.
- Made the notebook select either the compact fixture or a full manifest through `POLITIKS_SOURCE_MANIFEST`, without network access.
- Added a dated quality report covering retrieval integrity, historical bounds, chambers, vote types, semantic gaps, affairs, people, dated memberships, duplicates, decision tokens, and aggregate anomalies.

### Verification

- `scripts/validate_source_snapshot.py --manifest source/manifests/full_swiss_2026-07-14.jsonl`: 362 files, 24,441,153 bytes, zero unresolved errors.
- `python -m pytest`: 19 passed, covering all three official workbook layouts and descriptive aggregate labels.
- Two independent clean full imports produced identical logical counts: 34,646 source records, 21,569 voting events, and 3,861,590 choices.
- The full notebook executed top to bottom with 362 source files, zero unresolved chambers, zero stable-identifier duplicates, and zero foreign-key violations.
- Full coverage is 18,647 National Council events from 2011-12-05 and 2,922 Council of States events from 2022-02-28, both through 2026-06-19.

### Known limitations and next implications

- Seventeen procedural/unassigned events have no affair link. Older National Council rows omit 444 Yes meanings, 446 No meanings, and 4,544 exact questions; those fields remain null.
- The workbook snapshot provides 7,171 affair identities/titles/types but not per-affair text/summary/topic detail. Classification must label this input limitation and can enrich it through a separately versioned official-detail acquisition.
- 39,445 choices (1.02%) lack a date-valid party interval and 120 lack a date-valid faction interval; no current membership is projected backward to fill them.
- Official aggregate/member matrices differ in 179 National Council events across five workbooks. Nineteen 2023 rows contain doubled published aggregates; ambiguous 2014 blank cells are not assigned to individual people. Raw aggregates and explicit choices remain separate.
- National Council 2003–2011 and the narrower 1996–2003 public scope need another official adapter. Council of States 2014–2021 requires Official Bulletin protocol extraction.

## Milestone 4 — Auditable topic and beneficiary classification

### Goal

Add a reproducible discovery layer while keeping official facts, pending deterministic/model suggestions, human review decisions, reviewed classifications, and user interpretations structurally separate.

### Work completed

- Added the versioned German taxonomy `1.0.0` for 18 policy topics, 16 affected groups, six effect mechanisms, effect direction, directness, and review status.
- Added transparent German rule definitions that create source-passage-backed pending suggestions. Named groups default to `affected` with unclear effect; beneficiary/cost-bearer roles require explicit wording.
- Added schema tables for immutable taxonomy checksums, classification-run configuration/provenance, stable pending suggestions, append-only review revisions, and source-linked evidence.
- Added the `reviewed_classification` view, which exposes only the latest accepted/edited decision and includes source snapshot, taxonomy version, method, reviewer, and evidence. Pending/rejected suggestions cannot enter this surface.
- Added a provider-neutral optional model interface that records provider, model, prompt version, configuration, confidence, and a passage verified against its source field. It cannot create review rows.
- Added a controlled JSONL review workflow with accepted/edited/rejected decisions, strict sequential revisions, record/file checksums, and collision detection.
- Added a bounded provenance-rich review-queue exporter.
- Added a rebuildable vote-search projection with ordinary indexes for canonical/display affair IDs, vote IDs, registration numbers, and dates, plus FTS5 over titles, exact questions, Yes/No meanings, official text/metadata, and reviewed labels.
- Added a labeled German benchmark with clear, procedural, ambiguous, and mixed-effect cases plus a report of known error modes.

### Verification

- `python -m pytest`: 24 passed, including deterministic idempotency, review history, reviewed-only publication, fake-provider provenance, source-evidence validation, and exact/full-text search.
- `scripts/evaluate_classification_benchmark.py`: 5 of 5 cases passed; procedural and ambiguous cases received no forced benefit, cost, or mechanism.
- Full run: 21,569 targets, 10,111 pending suggestions (9,301 topic, 803 unclear affected-group, six mechanism, one explicit beneficiary), zero reviewed labels, and 21,569 search documents.
- Two consecutive full classification runs returned the same run key and logical counts.
- Canonical affair ID `20214377`, chamber vote ID `NR:27660`, registration `32381`, date, German umlaut text, and ordinary full text all returned direct/search results independently of reviewed classification.
- Full generated database retained zero foreign-key violations.

### Known limitations and next implications

- The benchmark is synthetic and too small to estimate precision/recall. Reviewed real examples and disagreement notes should expand it over time.
- German rules miss multilingual terms, synonyms, negation, legal context, and indirect/distributional effects. Topic labels do not establish whether Yes supports the overall affair.
- Full per-affair text/topic enrichment remains absent, so the first pass mainly uses event titles, questions, and semantics. Every suggestion records the field/passages actually used.
- No real suggestion is marked reviewed in the committed review file. This is intentional: publication begins only after an identified reviewer evaluates source evidence.

## Milestone 5 — MariaDB schema and deterministic publication

### Goal

Create the deployed application schema and a deterministic, rollback-safe boundary that publishes the SQLite read model without creating a second factual-authoring path.

### Work completed

- Added a MariaDB 10.6-compatible InnoDB/`utf8mb4` schema with immutable publication metadata, a single active-snapshot pointer, and 27 country-neutral `ref_*` read-model tables.
- Added application-owned users, insights, selected members, selected vote evidence, campaign-context items, draft/unlisted/public visibility, opaque public IDs, hashed share tokens, timestamps, and ownership/reference foreign keys.
- Bound every insight and its parliamentary evidence to one immutable reference publication through composite keys, preserving its factual snapshot after future activations.
- Added exact identifier/date indexes and a FULLTEXT vote-search projection while retaining official questions, Yes/No meanings, provenance URLs/checksums, and reviewed labels separately.
- Added guarded CLI-only environment loading, PDO connection, schema bootstrap, deterministic publication, and read-only publication verification outside the public site root.
- Made publication keys depend on the read-model version, source snapshot/schema, source-file digest, taxonomy version/digest, and reviewed-classification digest. Unchanged input reuses the same publication.
- Published all tables in a single transaction, reconciled source/destination row counts per table, computed a deterministic content checksum, and changed the active pointer only after completion.
- Exported only the SQLite `reviewed_classification` view. Pending/rejected suggestions cannot enter the MariaDB application surface.
- Guarded database creation, destructive reset, artificial publication keys, and failure injection to a file named `.env.test`.

### Verification

- Clean `.env.test` bootstrap applied 35 schema statements and created 34 tables.
- Fixture publication created publication 1 with 27 reconciled tables, including 54 voting events, 2,241 individual choices, 54 vote-search documents, and zero reviewed classifications.
- Publishing identical input a second time returned `reused: true`, the same publication ID, and identical counts.
- Injecting a failure after `ref_person` exited non-zero. The enclosing transaction left exactly one publication row, zero loading rows, and active publication 1 unchanged.
- `scripts/verify_reference_publication.php` reconciled all 27 recorded table counts and exercised exact vote-identifier lookup plus 83 date-valid party/member/vote fixture links.
- `php tests/php/run.php` covered the environment parser, schema contract, statement parser, and non-public CLI boundary in addition to foundation checks.

### Known limitations and next implications

- The local database returned a generic MySQL-compatible server version rather than identifying itself as MariaDB. The schema deliberately uses MariaDB 10.6-compatible constructs, but final confirmation against the user's MariaDB 10.6.18 host remains a deployment acceptance check.
- Local acceptance used the compact fixture for speed. The same mapping accepts the full classified SQLite snapshot; production publication must be run from a freshly rebuilt and verified full database.
- No real classification has been human-reviewed, so the correctly published reviewed-classification count is zero.
- Runtime queries must join current catalogue/search data through `reference_state`, while saved insights must continue using their stored `reference_publication_id`.

## Milestone 6 — Deployable PHP shell, security baseline, and Google authentication

### Goal

Establish the framework-free production runtime under `site/`, secure its HTTP/session boundary, and implement Google-only authentication with server-side token verification.

### Work completed

- Replaced the placeholder with a German signed-out/signed-in application shell, responsive navigation, accessible landmarks/focus behavior, public-catalogue placeholder, and full-width primary actions.
- Added a persisted system/light/dark theme selector with an early local theme script to prevent a theme flash.
- Pinned and copied Bootstrap 5.3.8, Bootstrap Icons 1.13.1, their required fonts, and licenses into the deployable assets. Google Identity Services remains the only remote browser script, as required by Google.
- Added a small autoloaded PHP request structure with validated environment loading, lazy PDO connectivity, bounded JSON parsing, consistent errors, and production-safe startup behavior.
- Added Apache front-controller and deny rules plus a matching local PHP router. Environment variants, backend PHP, schema files, storage, and logs return 404 and cannot expose source.
- Added a restrictive CSP covering same-origin assets and only Google's documented Identity Services script/style/frame/connect endpoints and profile-image host, with framing, MIME, referrer, and permissions headers.
- Added strict HTTP-only SameSite session cookies, HTTPS-aware secure cookies, session-ID rotation on login, complete logout destruction, and per-session CSRF protection for every mutation.
- Added `GET /api/auth-config`, `GET /api/session`, `POST /api/google-login`, and `POST /api/logout` with stable German JSON errors.
- Implemented dependency-injected Google JWT verification: three-part JWT structure, RS256, key ID, JWKS lookup/cache, RSA JWK or certificate conversion, OpenSSL signature, exact audience, issuer, expiration, verified email, bounded identity fields, and HTTPS Google avatar restriction.
- Implemented transactional MariaDB user create/reuse/link behavior. Stable Google subject is primary; a verified unique email can link an existing unlinked record; conflicting linked identities and disabled accounts are rejected.
- Added an explicitly double-guarded test verifier (`APP_ENV=test` plus `POLITIKS_TEST_AUTH=enabled`) used only by Playwright. Production cannot select it with ordinary configuration.
- Added protected cache/log/upload runtime roots and documented Google Cloud, local server, deployment, endpoint, CSP, and PHP-extension setup.

### Verification

- PHP syntax checks passed across all runtime and test PHP files.
- Pure PHP suite: 21 authentication/security/foundation tests passed, including malformed JWT, signature, audience, issuer, expiration, unverified email, unavailable keys/OpenSSL, disabled configuration, PEM conversion, session rotation, and CSRF.
- MariaDB integration created and reused one Google user, linked an existing unlinked user by verified email without duplication, and rejected a disabled account; test rows were removed afterward.
- Playwright covered configured/disabled Google states, login/logout, HTTP-only SameSite cookie rotation, missing/invalid credentials, CSRF rejection, local assets, private-path denial, responsive navigation, and persisted theme behavior.
- Reviewed visual baselines cover signed-out full pages and signed-in hero states on desktop/mobile in light/dark modes. An unstable Chromium mobile full-page stitching artifact was isolated by using viewport-level authenticated baselines; ordinary browser rendering remained correct.
- Clean schema reset/publication retained 35 schema statements, 34 tables, and the active fixture publication after making `google_sub` nullable for safe verified-email linking.

### Known limitations and next implications

- The local PHP CLI remains 8.2.30 and lacks OpenSSL, cURL, and `mbstring`. Production JWT signature execution therefore still requires a smoke check on PHP 8.4 with the target extensions; deterministic tests cover its failure boundary and all claim validation.
- The local database identifies only as MySQL-compatible. MariaDB 10.6.18 host verification remains part of final deployment acceptance.
- Google login has been exercised with the injected verifier, not a live Google account, so the production authorised origin and client ID must be smoke-tested on the target HTTPS origin.
- Milestone 8 replaces the compact lifecycle editor with the complete evidence wizard while preserving the visibility and ownership boundary established here.

## Milestone 7 — Public catalogue and personal insight management

### Goal

Deliver the public landing catalogue and a complete authorization-correct insight lifecycle before introducing the evidence wizard.

### Work completed

- Added a deterministic paginated public catalogue shared by signed-out and signed-in landing pages, with full-width Bootstrap accordion cards and German loading, empty, error, and incremental-loading states.
- Added the authenticated “Meine Insights” workspace containing draft, unlisted, and public records, status labels, create/edit controls, and archive behavior.
- Added prepared-query lifecycle storage with transactionally locked updates, bounded text and pagination, opaque 26-character public IDs, archived-record exclusion, and server-side owner checks returning indistinguishable 404 responses on tampering.
- Allowed incomplete scope fields only for drafts while retaining the mandatory immutable reference-publication link. Public transition currently requires title and claim; Milestone 8 adds scope/member/evidence publication validation.
- Added 256-bit random unlisted tokens stored only as SHA-256 hashes. Regenerating an unlisted link invalidates its predecessor; shared pages and APIs emit `noindex, nofollow` headers and the page includes matching robot metadata.
- Added catalogue hydration for scope, counts, and selected vote evidence while keeping author claims, notes, and official parliamentary fields visually distinct.
- Added a deterministic two-user browser fixture with all three visibility states and one representative official vote, plus isolated MariaDB lifecycle integration tests.
- Added Playwright coverage for public isolation, keyboard accordion state, owner lifecycle, anonymous mutation rejection, malformed pagination, unlisted sharing, and reviewed empty/populated desktop/mobile light/dark baselines.

### Verification

- MariaDB lifecycle integration passed create, owner listing, draft isolation, unlisted sharing, public transition, cross-owner mutation denial, and archive removal.
- Playwright: 26 tests passed across Chromium desktop and mobile, including eight catalogue-state baselines and the existing eight application-shell baselines.
- Visual review found no unintended overflow, clipping, theme contrast failure, or inaccessible mobile card composition in representative populated, empty, and signed-in states.
- Clean test schema bootstrap retained 35 statements and 34 tables; fixture publication reconciled all 27 reference tables before deterministic catalogue seeding.

### Known limitations and next implications

- The compact editor covers lifecycle text and visibility only. Scope, members, vote search, evidence ordering, and full publication validation are Milestone 8 work.
- An unlisted token is intentionally unrecoverable from its stored hash. Saving an unlisted record through the current editor issues a fresh link and explicitly warns that the previous link is invalid.
- Public evidence rendering is deliberately concise until the wizard supplies the complete vote semantics, member choices, provenance links, and limitations required by the product contract.

## Milestone 8 — Five-step insight wizard and parliamentary evidence search

### Goal

Replace the compact editor with the complete guided evidence workflow and make the vote step a responsive analytical workspace for changing a selected party-member cohort.

### Work completed

- Added the accessible German five-step assistant with direct tab navigation, Back/Next controls, grouped validation that opens and focuses the first invalid step, desktop chevrons, and a stacked mobile layout.
- Added owner-only, CSRF-protected scope, member, vote-search, and evidence APIs. Every lookup remains pinned to the insight's immutable reference publication.
- Made scope changes transactionally clear member and evidence selections, preventing stale choices from surviving a changed chamber, party, or period.
- Implemented historical member eligibility as the intersection of formal-party membership, chamber mandate, and selected period, while displaying dated faction and party membership separately on each vote.
- Kept one authoritative member set synchronized between Steps 2 and 3. Entering Step 3 captures the latest Step 2 set as the reset baseline; toggling members persists and recomputes without a reload.
- Implemented cohort direction from Yes-versus-No only, with ties and abstention-only votes neutral, and explicit eligible, participating, abstaining, absent, and no-mandate counts.
- Added full-text and exact-identifier vote search with matching context, default final/overall prioritization, and direction, cohesion, type, official-topic, reviewed-classification, and divergent-member filters.
- Added balanced green/red/neutral desktop columns and segmented mobile results. Collapsed cards expose identifiers, date, type, tallies, denominators, cohesion, and separately labeled official/reviewed metadata; details expose the exact question, Yes/No semantics, aggregate result, limitations, dated memberships, and official source.
- Added neutral divergent-vote labels and an outlier panel based only on evaluated Yes/No choices in the current result set.
- Added persistent, reorderable evidence selection that survives search and cohort changes. Selected no-participation evidence remains visible with a warning and blocks publication; abstention-only evidence remains valid and non-directional.
- Strengthened public publication validation to require complete scope, members, evidence with recorded selected-member participation, title, and claim while preserving incomplete drafts.
- Added a deliberately synthetic, test-only reference publication with four MPs and five designed votes. It exercises deterministic Yes, No, Split, non-directional, abstention, missing-participation, outlier, official-topic, and reviewed-classification cases without entering production tooling.
- Added desktop/mobile light/dark vote-workspace baselines and removed mobile sticky overlays that could trap card actions between the navigation, cohort controls, and evidence tray.

### Verification

- MariaDB integration verified date-valid eligibility; deterministic Yes/No/Split/non-directional regrouping; exact identifier and match context; separate official/reviewed labels; immutable publication/event evidence identifiers; evidence ordering; no-participation publication rejection; and abstention-only publication acceptance.
- Playwright covered the complete creation/resume/edit/publish path, synchronized member changes, reset semantics, filters, detailed vote inspection, outlier discovery, evidence retention, first-invalid-step focus, and both responsive layouts.
- Four reviewed visual references cover the analytical workspace on desktop/mobile in light/dark modes. No unintended horizontal overflow, clipped actions, illegible contrast, or mobile sticky-control obstruction remained.
- Full `npm.cmd run verify`: 21 pure PHP tests, all three MariaDB integration suites, and 34 Playwright cases passed.

### Known limitations and next implications

- Filter facets are derived from the bounded result set rather than a separate global aggregation query. The server returns at most 100 matching votes and clearly labels a limited result.
- The outlier summary deliberately describes the current search result, so narrowing the query changes its evaluated-vote denominator.
- The compact official acquisition fixture cannot truthfully test chamber-scoped cohorts because those sampled events have unknown chamber provenance. The synthetic fixture is isolated to tests; production behavior still depends on a verified full Swiss reference publication.
- Campaign posters, images, YouTube videos, and ordinary links are intentionally deferred to Milestone 9. The assistant clearly labels that boundary in Step 4.

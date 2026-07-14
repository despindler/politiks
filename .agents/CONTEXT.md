# Politiks project context

## 1. Product purpose

Politiks is an evidence-oriented web application for examining how political parties and individual legislators actually voted and comparing that conduct with the messages parties use while campaigning.

The motivating use case is a possible contradiction between political messaging and parliamentary conduct. For example, a party may campaign as representing lower-income voters while supporting measures that primarily benefit high-income individuals or large companies. Politiks must help a user assemble the official voting evidence for such an argument without presenting the user's interpretation as an official or uncontested fact.

An **insight** is the central user-created object. It combines:

- a user-authored claim or talking point;
- a country, legislature, chamber, party, period, and selected party members;
- one or more precisely identified parliamentary votes;
- the recorded choices of the selected members;
- explanatory notes connecting the records to the claim;
- optional user-supplied campaign context, such as campaign-poster images, YouTube videos, or external links; and
- complete provenance and links back to the official parliamentary material.

The application should make useful political patterns visible while preserving the distinction between:

1. official source records;
2. derived or model-assisted classifications;
3. reviewed classifications; and
4. a user's political interpretation.

## 2. MVP scope

The MVP uses voting records from the Swiss Federal Assembly:

- National Council (`Nationalrat`);
- Council of States (`Ständerat`);
- parliamentary affairs and descriptive texts;
- voting events and individual recorded choices;
- people and time-valid party and parliamentary-group memberships; and
- official topics, descriptors, sessions, committees, cantons, and legislative periods where available.

The first user interface language is German. Preserve source languages and language metadata exactly. Do not assume every official source record is German, and do not discard French, Italian, or Romansh values when available.

The schema and application domain must be country-neutral from the beginning, but the MVP must remain Swiss-only. Country-specific source fields and procedural concepts belong in Swiss adapters or source-specific staging structures rather than being forced into false universal equivalences.

## 3. Research and data objectives

The underlying data should eventually support questions such as:

- How frequently do Swiss parties vote together?
- How cohesive is a party, and which members depart from its majority?
- Which coalitions form around taxation, business regulation, social policy, health care, education, science, foreign relations, migration, and other subjects?
- Which measures directly benefit or impose costs on groups such as low-income households, middle-income households, employees, pensioners, families, students, researchers, SMEs, large companies, patients, health-care providers, farmers, cantons, or municipalities?
- How do those patterns change over time?
- Does the evidence assembled by a user support a claimed contrast with a party's campaign messaging?

Party affiliation must be resolved on the date of each vote. Formal party and parliamentary group or faction are separate concepts and must both be preserved. The UI selects a formal party and shows faction information separately.

## 4. Official Swiss sources

Use the official open-data services of the Swiss Parliamentary Services as the primary factual source.

- Information page: <https://www.parlament.ch/de/%C3%BCber-das-parlament/fakten-und-zahlen/open-data-web-services>
- Existing service base URL: <http://ws-old.parlament.ch>
- Short documentation: <https://www.parlament.ch/centers/documents/_layouts/15/DocIdRedir.aspx?ID=DOCID-1-8566>

Attribute official data to:

`Parlamentsdienste der Bundesversammlung, Bern`

Record exact retrieval times, URLs, parameters, response status, byte counts, and checksums. Preserve raw responses before normalisation. Treat the service's current behavior and returned schemas as authoritative over old prose documentation, and document observed discrepancies.

Endpoints to investigate include:

- affairs: `/affairs`, `/affairs/<ID>`, `/affairs/types`, `/affairs/states`, `/affairs/topics`, `/affairs/descriptors`, `/affairsummaries`, and `/affairsummaries/<ID>`;
- votes: `/votes/affairs`, `/votes/affairs/<ID>`, `/votes/councillors`, and `/votes/councilors/<ID>`, including exact vote subjects and Yes/No meanings;
- people and memberships: `/councillors`, basic details, historic councillors, person details, `/Factions`, historic factions, historic parties, councils, sessions, legislative periods, cantons, and committees.

The service has known pagination and naming inconsistencies, including `councillor` versus `councilor`. Empty pages may be returned as HTTP 404. Confirm behavior with small requests before full acquisition.

Prefer JSON whenever it contains the required fields. Do not scrape the HTML representation, which can omit fields present in JSON or XML. Use `lang=de` by default and preserve multilingual fields returned together. Most list endpoints are documented as returning at most 50 entries per page, with page numbering starting at 1. Treat a 404 as the end of pagination only after earlier pages for that request succeeded; an initial or unexpected 404 remains an error to investigate.

The CURIAplus status documentation supplied by the repository owner explains the July 2023 transition in affair-status and standard-decision codes. It is supporting documentation, not a substitute for affair or vote records.

## 5. Data pipeline and storage boundaries

Keep these concerns separate:

### 5.1 Raw acquisition

Raw official responses live under `./source/` and remain substantively unmodified. Store manifests, documentation, page responses, and detail responses in clear source-specific directories. Acquisition must be resumable, rate-limited, retry transient failures, validate JSON, calculate SHA256 checksums, and never silently overwrite a different snapshot.

Prefer one response file per list page and one response file per detail entity. Use deterministic names such as:

```text
source/affairs/list/page_000001.json
source/affairs/detail/20240001.json
source/votes/affairs/list/page_000001.json
source/votes/affairs/detail/20240001.json
source/councillors/historic/page_000001.json
```

Archive a previous response or use snapshot-dated directories when refreshing data. Every manifest entry must contain at least the source URL, endpoint, query parameters, local path, UTC retrieval timestamp, HTTP status, content type, byte count, SHA256, success/error state, retry count, and optional notes. A response is successful only after it is completely stored and parses as expected.

Download list and vote-affair indexes before scheduling all detail requests. For the MVP, prioritize affairs connected to recorded votes while keeping the acquisition design capable of later retrieving every affair. Place supplied source documentation under `source/documentation/` and explain the snapshot layout in `source/README.md`.

### 5.2 Reproducible research database

`./database/schema.sql` is the authoritative SQLite schema for the locally reproducible research database. `./notebooks/01_import_source_data.ipynb` recreates the SQLite database and imports all supported local raw data without accessing the network. It must run from top to bottom, contain a concise Markdown explanation above each code cell, produce bounded informative output, and finish with integrity and coverage checks.

Separate source provenance from normalized entities. Keep source identifiers distinct from internal keys, current membership distinct from historical membership, affair-level decisions distinct from individual choices, and party distinct from faction. Preserve all original text, language, text role, ordering, identifiers, and encoded HTML without destructive cleanup. Use foreign keys, stable uniqueness constraints, date ranges, and indexes on common joins and filters.

The notebook must resolve the repository root without assuming its launch directory, recreate the database from `schema.sql`, use explicit transactions with rollback, import every supported raw file found under `source/`, report skipped files with reasons, and end with bounded previews and validation queries. At minimum validate table counts, chamber/year coverage, recorded-choice counts, representative joins, unresolved dated memberships, unlinked affairs, duplicate identifiers, and zero foreign-key violations.

The initial ingestion work package preserves descriptive text but performs no NLP, political interpretation, beneficiary classification, embeddings, or LLM calls.

### 5.3 Classification layer

Topic and beneficiary classification is a separate, auditable work package. Use a hybrid process:

- deterministic use of official topics, descriptors, named laws, committees, and keywords;
- model-assisted suggestions from substantive text when configured;
- confidence, direction of effect, taxonomy version, method, and supporting passages;
- explicit human review state; and
- publication of reviewed labels only.

Keep policy topic separate from beneficiary or cost-bearer. Represent direct benefit, direct cost, indirect or claimed effects, regulation changes, tax changes, transfers, and eligibility changes separately where appropriate. Low-confidence, politically salient, procedural, or mixed-effect cases require review.

Users must also be able to locate a known vote directly by identifiers, dates, titles, affair numbers, or text without depending on classification.

### 5.4 Deployed MySQL/MariaDB application database

The deployed PHP application uses MariaDB/MySQL, not the SQLite file. Provide a deterministic publishing process that transfers the required normalised parliamentary records and reviewed classifications from the research database into the application database.

Keep application-owned data—users, sessions if stored in the database, insights, selected evidence, campaign-context attachments, and visibility state—separate from imported parliamentary reference data. Do not create two independently maintained factual sources of truth.

## 6. Vote evidence rules

Users choose the votes used in an insight. Search should initially prioritize substantive and final votes where those can be identified reliably, while allowing all recorded vote types to be found and selected.

Every displayed or selected voting event must make these fields visible when the source provides them:

- country, legislature, chamber, session, and date;
- affair number and title;
- voting-event identifier and vote type;
- exact question or subject;
- the meaning of Yes and No;
- aggregate result;
- each selected member's recorded choice;
- party and faction membership valid on that date;
- official source link and retrieval provenance; and
- any known limitation or missing semantic field.

Never infer that Yes means support for the overall reform. Amendment, minority, return, procedural, and final votes must remain distinguishable.

## 7. Authentication and users

The MVP uses **Google Sign-In only**. Follow `.agents/skills/GOOGLEAUTH.md`.

The browser obtains a Google ID token and passes it to PHP. PHP must verify the token signature and claims server-side, including audience, issuer, expiration, verified email, algorithm, key identifier, and signature using Google's JWKS. Use Google's stable `sub` claim as the external identity. A verified email may be used for safe account linking under the unique-email account model.

Use ordinary server-side PHP sessions after login. Google login must be disabled cleanly when `GOOGLE_CLIENT_ID` is absent. Newly created accounts receive the normal user role and can never acquire elevated privileges automatically.

## 8. Insight visibility and catalogue behavior

Insights have exactly these initial visibility states:

- `draft`: visible only to its creator;
- `unlisted`: available to its creator and anyone with its non-guessable share URL, but absent from general listings; and
- `public`: visible to everyone and included in public listings.

Both the signed-out and signed-in landing pages contain a section listing all **public** insights created by all users. Each list item is a Bootstrap accordion entry containing the insight details and evidence summary. The list must be paginated or incrementally loaded once its size makes an unbounded response impractical.

A signed-in user additionally sees a clearly separate "Meine Insights" area containing all their own draft, unlisted, and public insights with edit controls. A non-owner must never gain edit access by manipulating identifiers or URLs.

## 9. Insight creation workflow

Use the accessible chevron stepper described in `.agents/skills/STEPPER.md`. The wizard has five steps:

1. **Rahmen** — select country, legislature/chamber, period, and party.
2. **Mitglieder** — select all or particular people who belonged to that party during the relevant period and show faction context.
3. **Abstimmungen** — interactively vary the selected member cohort, search and juxtapose its Yes/No voting behavior, inspect exact records, and select evidence.
4. **Einordnung** — write the claim, explanatory notes, and campaign context; attach supported images, YouTube references, or external links.
5. **Prüfen** — review sources, validation, visibility, and save or publish.

Users may navigate directly between steps. Required validation happens on save, opens the first step containing invalid or missing input, announces the problem accessibly, and focuses the first invalid control. Draft saving may permit incomplete content, while public publication requires all mandatory evidence and attribution fields.

### 9.1 Vote exploration and member-cohort behavior

The `Abstimmungen` step is the main analytical workspace and should receive the greatest UX attention. Step 2 establishes an initial member cohort. Step 3 repeats that cohort in a prominent sticky control and lets users deselect and re-select members without leaving the vote results. Changes operate on the same shared selection state and therefore remain synchronized with Step 2.

Provide:

- selected-member chips or checkboxes with names and, where available, portraits;
- selected and eligible counts;
- member search for large parties;
- `Alle auswählen` and a clear reset to the cohort that was active when Step 3 was entered;
- an optional `Nur Abweichler anzeigen` exploration filter; and
- a concise explanation of the current calculation basis, such as `Mehrheit von 8 ausgewählten Mitgliedern` or `Individuelles Stimmverhalten von …`.

If the user returns to Step 2, changes the cohort deliberately, and enters Step 3 again, that new selection becomes the reset baseline. At least one member is required to calculate a directional result. Do not maintain conflicting hidden copies of the selection in different steps.

Every member-selection change must recompute, without a full page reload:

- which result group contains each voting event;
- Yes, No, abstention, other-participation, and non-participation counts;
- participation denominators and cohesion;
- result totals and active Yes/No filters;
- outlier or divergent-vote indicators; and
- the tallies shown on evidence already selected for the insight.

Preserve search text, filters, scroll context, and already selected evidence while recalculating. Do not silently remove evidence. If no currently selected member has any recorded participation in previously selected evidence, retain it with a prominent warning and require the user to resolve or remove it before public publication. Abstention-only evidence remains valid but is presented as non-directional.

### 9.2 Yes/No juxtaposition

On desktop, present the result set as two balanced columns of full-width cards within their columns:

- pale green `Ja` results;
- pale red `Nein` results; and
- a clearly accessible neutral area or view for `Geteilt` and abstention-only/otherwise non-directional results.

On narrow screens, replace simultaneous columns with accessible segmented tabs or filters such as `Ja (128)`, `Nein (94)`, and `Geteilt (17)`, each containing full-width cards. Do not rely on red and green alone: combine restrained surface tint with explicit text, icon, and border treatment that works in light and dark modes.

Classify a voting event for the active cohort as:

- `Ja` when more selected participating members recorded Yes than No;
- `Nein` when more recorded No than Yes;
- `Geteilt` when Yes and No are tied and at least one substantive Yes/No choice exists; and
- non-directional when selected members participated but none recorded Yes or No.

Abstentions and absences do not determine direction. Cohesion uses Yes and No choices only and must expose or explain its denominator. The card must separately show members who abstained, did not participate, or had no mandate on that voting date. A voting event is irrelevant to the active cohort only when none of its selected members has a relevant recorded participation.

### 9.3 Search, cards, details, and outliers

Place a prominent full-width keyword search above the result groups. Search substantive indexed properties, including affair and voting identifiers, titles, exact questions, Yes/No meanings, summaries and descriptive text, official topics/descriptors, named legislation, committees, and reviewed classifications. Highlight representative matching passages. Exact identifiers must work independently of full-text ranking or classification.

Provide filters for current-cohort direction (`Alle`, `Ja`, `Nein`, `Geteilt`), cohesion (`Einstimmig`, `Mehrheitlich`, `Knapp`), member, chamber, period, vote type, official topic, and reviewed classification. State explicitly that the direction filter describes the selected cohort rather than the overall chamber outcome. Prioritize identifiable substantive and final votes by default without making other recorded types unreachable.

Each collapsed card should show:

- direction, title, affair number, date, chamber, and vote type;
- a tally such as `Ja 5 · Nein 1 · Enthalten 0`;
- how many selected members were eligible and participated;
- cohesion and unanimity where meaningful;
- official and reviewed classification badges; and
- a full-width control to add or remove the vote as insight evidence.

Its accordion details or detail modal must show the exact question, meaning of Yes/No, overall chamber result, matching source text, official links, provenance, known limitations, and every selected member grouped by recorded choice. Show time-valid party/faction information and distinguish `Nicht teilgenommen` from `Zu diesem Zeitpunkt kein Mandat`.

Mark a member who voted against the current cohort majority with a neutral `Abweichende Stimme` label; do not imply motive or disloyalty. Offer an outlier summary over the current result set showing, for each selected member, evaluated votes, agreement with the cohort majority, and divergent choices. Selecting a member from that summary may focus or toggle that member in the active cohort.

Keep an always-visible or easily opened evidence tray showing the number of selected voting events. It must let the user review, reorder, or remove evidence without losing the current search state.

## 10. Campaign-context media

Campaign statements and media are user-supplied context. They are not official parliamentary evidence. Label them accordingly.

For the MVP support:

- validated image uploads;
- YouTube video URLs rendered through a narrowly validated embed or privacy-conscious link; and
- ordinary external URLs with an optional user-written label and description.

Do not accept arbitrary HTML or arbitrary embed code. Validate MIME type and decoded image content server-side, impose size limits, generate server-controlled file names, prevent script execution in upload storage, and escape all user-authored output. Store attribution and source URL fields where relevant.

## 11. Web application and deployment constraints

The deployable application lives entirely under `./site/`. The web host requires `site/index.php` or `site/index.html` at that directory's root, with every deployed runtime file underneath it.

Required stack:

- PHP 8.4;
- MariaDB 10.6.18 compatible SQL;
- Apache with `.htaccess` support;
- HTML5;
- Bootstrap 5 CSS and JavaScript;
- Bootstrap Icons;
- plain browser JavaScript; and
- PDO for database access.

Do not introduce a PHP framework or a front-end framework without explicit approval. Keep dependencies small. Vendor pinned Bootstrap and Bootstrap Icons runtime assets under `site/` so the deployment is not unnecessarily dependent on a CDN. Google Identity Services remains an external authentication dependency.

Production credentials and secrets live in `site/.env`, which is never committed. Local automated tests use an ignored repository-root `.env.test`. `.env.example` documents every supported setting with non-secret placeholders. Because `site/` is the public document root, Apache rules must deny HTTP access to `.env`, configuration, database scripts, logs, private upload storage, and other non-public files. Never expose a web-accessible database installer.

Assume OpenSSL, cURL, PDO MySQL, and `mbstring` are required PHP extensions. Verify them explicitly and provide a useful setup error. Composer may be used only when a small dependency brings a clear security or maintenance benefit; committed/deployed runtime dependencies must work on the stated host. Do not depend on cron or shell access for ordinary web requests. Data publication may remain a controlled local or deployment-time operation.

## 12. User-interface direction

The German interface should be minimal, elegant, sophisticated, and intuitive.

- Use Bootstrap's grid intentionally for readable columns and responsive stacking.
- Use Bootstrap modals for focused inspection or confirmation when a separate page would interrupt the workflow.
- Use Bootstrap Icons with accessible text or labels.
- Buttons span the available width by default. Explicitly opt compact utility buttons out when full width would harm dense toolbars, pagination, accordion controls, or icon-only actions.
- Provide a visible light/dark-mode switch. Use Bootstrap color modes, respect the operating-system preference on first visit, persist the user's selection, and avoid flashes of the wrong theme.
- Preserve visible keyboard focus, adequate contrast, semantic headings, labels, live error announcements, and keyboard-operable accordions, modals, and stepper controls.
- Design desktop and mobile layouts together rather than treating mobile as a later patch.

## 13. Testing and quality expectations

Use deterministic tests at the appropriate boundaries:

- unit tests for parsing, classification helpers, authorization rules, visibility rules, and pure domain logic;
- integration tests against the MariaDB test database configured in `.env.test`;
- API tests for authentication, ownership, insight CRUD, publishing, vote search, attachments, and stable JSON errors;
- import checks for checksums, idempotency, foreign keys, duplicates, coverage, and historical membership joins; and
- Playwright browser tests for critical user flows and visual behavior.

Playwright coverage must include signed-out and signed-in landing pages, public insight accordions, owner-only management, the five-step wizard, interactive member-cohort changes, Yes/No/Split vote regrouping, outlier discovery, vote inspection, validation, visibility changes, Google-login disabled/configured states, responsive navigation, modals, uploads/links, and unauthorized access.

Maintain reviewed screenshot baselines for representative desktop and mobile viewports in both light and dark modes. Keep test data, time, animations, and viewport deterministic. Visual snapshots supplement semantic and behavioral assertions; they do not replace them.

## 14. Security and integrity requirements

- Use prepared PDO statements and explicit transactions.
- Regenerate the PHP session identifier after authentication.
- Use secure, HTTP-only, same-site session cookies, with the secure flag in HTTPS production.
- Protect every state-changing request against CSRF.
- Enforce authorization on the server for every insight and attachment operation.
- Escape output by context and apply a restrictive Content Security Policy compatible with Google Sign-In and validated YouTube embeds.
- Validate and bound pagination, search filters, identifiers, text lengths, URLs, and uploads.
- Do not expose stack traces, SQL errors, secrets, or raw tokens to the browser.
- Log actionable operational failures without logging credentials, Google tokens, or sensitive session data.
- Preserve source provenance and classification history so displayed claims can be audited.

## 15. Repository shape

The intended top-level shape is:

```text
.
├── .agents/
│   ├── CODEX.md
│   ├── CONTEXT.md
│   ├── PLAN.md
│   ├── messageinabottle.md
│   └── skills/
├── source/
├── database/
│   ├── schema.sql
│   └── parliament.sqlite          # generated full snapshot; stored through Git LFS for deployment handoff
├── notebooks/
│   └── 01_import_source_data.ipynb
├── scripts/
├── src/                           # optional reusable ingestion/publishing helpers
├── site/
│   ├── index.php
│   ├── .env                       # deployment secret; ignored
│   ├── .htaccess
│   ├── api/
│   ├── assets/
│   ├── backend/
│   ├── database/
│   └── storage/                   # protected non-public runtime data
├── tests/
├── .env.example
├── .env.test                      # local test credentials; ignored
├── requirements.txt
├── package.json
├── README.md
└── PROJECT.md
```

Adjust subdirectories when implementation evidence supports a simpler layout, but preserve the separation between raw acquisition, reproducible research data, deployment publishing, and application-owned state.

## 16. Product boundaries for the MVP

The MVP does not need:

- voting data from countries other than Switzerland;
- collection or automated interpretation of campaign material;
- social interactions, comments, likes, or follower systems;
- a general administration suite beyond what is required to operate and review data safely;
- automated publication of unreviewed political classifications;
- a PHP or JavaScript framework; or
- causal claims about the real-world effect of legislation.

The MVP is complete only when a user can authenticate with Google, find or inspect Swiss roll-call records, select a party and historically valid members, vary that cohort while exploring accurately regrouped Yes/No/Split voting behavior and outliers, assemble and save an evidence-backed insight, attach campaign context, control visibility, edit their own work, share unlisted work, and publish an insight that appears accurately on both public landing pages.

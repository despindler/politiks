# Politiks MVP implementation plan

## How to use this plan

Execute milestones in order unless a milestone explicitly permits parallel work. Before starting a milestone, read `.agents/CODEX.md`, `.agents/CONTEXT.md`, and the skill documents referenced by that milestone. Record completed work, verification commands, decisions, and known limitations in `PROJECT.md`.

Each milestone ends at a testable boundary. Do not claim completion while a required acceptance check is failing. Prefer small representative fixtures during development and keep full official-data acquisition resumable.

## Definition of the MVP

The MVP is a German-language, responsive PHP 8.4 application backed by MariaDB 10.6.18. It lets a user authenticate with Google, inspect Swiss parliamentary votes, select a formal party and time-valid members, vary that member cohort in a live Yes/No/Split voting workspace to identify divergent MPs, create an evidence-backed insight through a five-step wizard, attach user-provided campaign context, manage draft/unlisted/public visibility, and publish public insights to the accordion catalogue shown on both signed-out and signed-in landing pages.

The research pipeline remains reproducible in SQLite, while a deterministic publishing process supplies normalized reference data to MariaDB. The domain model is country-neutral, but only the Swiss adapter and Swiss data are implemented for the MVP.

## Milestone 0 — Repository foundation and executable contracts

### Goal

Establish the documented project structure, environment contract, test harnesses, and repeatable commands before substantive implementation.

### Outputs

- Root `README.md` with supported versions and exact setup, test, acquisition, import, publication, and local-run commands.
- `PROJECT.md` milestone log.
- Required top-level directories from `CONTEXT.md`, with concise READMEs where a directory's purpose is not self-evident.
- `.env.example` covering application, MariaDB, Google, session, upload, and test-server settings without secrets.
- `.gitignore` excluding `.env`, `.env.*` except `.env.example`, SQLite binaries, raw transient files, uploads, logs, caches, and test artifacts that should not be committed.
- Python environment definition for acquisition/import tooling.
- `package.json` with a pinned Playwright test dependency and documented browser installation.
- A minimal PHP test/bootstrap strategy appropriate to a framework-free application.
- Environment diagnostics that check PHP version and required extensions: PDO MySQL, OpenSSL, cURL, and `mbstring`.

### Verification

- A clean clone can install Python and Node dependencies using documented commands.
- Copying `.env.example` to ignored `.env.test` produces a parseable test configuration after credentials are filled in.
- `git check-ignore .env.test` succeeds and `.env.example` remains tracked.
- Playwright installs and runs a placeholder smoke test without requiring production credentials.
- The environment diagnostic exits successfully on the target local environment or reports every missing prerequisite clearly.

## Milestone 1 — Swiss source reconnaissance and acquisition proof

### Goal

Verify the official service's real schemas, identifiers, pagination, coverage, and join paths before designing the final database.

### Outputs

- An endpoint inventory documenting observed URLs, parameters, response shapes, languages, identifiers, pagination termination, and discrepancies from old documentation.
- A resumable acquisition script with timeouts, descriptive User-Agent, rate limiting, retry/backoff, JSON validation, SHA256, UTC timestamps, and structured logs.
- A small committed or reproducibly generated fixture snapshot spanning both chambers where possible, several affairs, multiple vote types, individual choices, and historical memberships.
- Raw-source directory layout and machine-readable manifest under `source/`.
- Coverage note describing National Council and Council of States limitations and which exact fields establish vote semantics.

### Verification

- Rerunning acquisition skips identical valid files and does not duplicate manifest success records incorrectly.
- A simulated transient failure retries and a malformed JSON response is not marked successful.
- Pagination stops correctly on the service's observed empty-page behavior.
- Every successful manifest row matches the stored file's size and SHA256.
- The fixture demonstrates the join from affair to voting event to individual choice to person and date-valid party/faction information, or documents the exact missing link.

## Milestone 2 — Country-neutral SQLite research model and import

### Goal

Create the authoritative reproducible research schema and import all supported local Swiss source files without classification.

### Outputs

- `database/schema.sql` with source provenance, country-neutral normalized entities, Swiss source mappings/staging as needed, constraints, and indexes.
- Tables for countries, legislatures, chambers, sessions, matters/affairs, texts, topics, descriptors, voting events, voting choices, people, parties, factions, and dated memberships.
- `notebooks/01_import_source_data.ipynb`, with one meaningful Markdown explanation above every code cell and concise useful output beneath major cells.
- Optional tested helpers under `src/` used transparently by the notebook.
- Import report describing supported record shapes and source limitations.

### Verification

- Executing every notebook cell top to bottom recreates `database/parliament.sqlite` from `schema.sql` without manual steps or network access.
- A second execution produces the same logical row counts and no duplicates.
- `PRAGMA foreign_key_check` returns no violations.
- Duplicate stable identifiers are absent or explicitly represented as source-version records.
- Validation queries report counts by chamber, year, and recorded choice.
- Representative queries join a choice to its event, affair, person, and party/faction membership valid on the voting date.
- The notebook clearly reports unlinked votes and unresolved memberships.

## Milestone 3 — Full Swiss snapshot and data-quality report

### Goal

Acquire and import the practical historical scope required for the MVP, with measurable completeness and provenance.

### Outputs

- A dated, resumable raw snapshot of the required Swiss endpoints and details under `source/`.
- Complete manifest and retrieval summary.
- Imported SQLite research database generated locally but ignored by Git.
- Data-quality report covering dates, chambers, vote types, missing affair links, missing Yes/No semantics, missing people, unresolved dated memberships, duplicates, and source coverage gaps.
- Reproduction instructions that distinguish small test fixtures from the full snapshot.

### Verification

- All stored successful responses pass checksum and JSON validation.
- All notebook integrity checks pass against the full snapshot.
- Counts reconcile with source aggregates where the service provides comparable totals.
- Known gaps are quantified rather than silently discarded.
- A fresh rebuild from the snapshot reproduces the same logical counts.

## Milestone 4 — Auditable topic and beneficiary classification

### Goal

Add a separate classification layer that supports discovery while keeping official facts, automated suggestions, review decisions, and user interpretations distinct.

### Outputs

- Versioned German taxonomy for policy topics, beneficiary groups, cost bearers, effect direction, direct/indirect status, and review status.
- Classification schema additions and recreation SQL.
- Deterministic first-pass rules using official topics, descriptors, committees, identifiers, and keywords.
- Provider-neutral interface for optional model-assisted suggestions, with recorded model/configuration, prompt version, confidence, evidence passages, and run identifier.
- Human-review workflow or controlled review file that accepts, edits, or rejects suggestions.
- Search fields that allow direct lookup by affair number, vote identifier, title, date, and full text independently of classification.
- A labeled benchmark set containing clear, ambiguous, procedural, and mixed-effect examples.

### Verification

- Re-running the same deterministic rules and configuration gives the same results.
- No unreviewed model suggestion is exported as a reviewed classification.
- Every published classification links to source text or official metadata and a taxonomy version.
- Benchmark results and known error modes are reported; ambiguous cases remain ambiguous rather than receiving forced labels.
- Direct identifier search finds known votes without any classification.

## Milestone 5 — MariaDB schema and deterministic publication

### Goal

Create the application database and a repeatable process for publishing parliamentary reference data from SQLite without duplicating factual authority.

### Outputs

- MariaDB-compatible schema under `site/database/` for imported reference data and application-owned data.
- Country-neutral reference tables corresponding to the required application read model.
- Application tables for users, insights, insight members, selected vote evidence, campaign-context items, visibility, share tokens, and timestamps.
- Deterministic publication tool that upserts or atomically replaces a versioned reference-data snapshot from SQLite.
- Publication-run metadata containing source snapshot, taxonomy version, row counts, time, and checksum/version identifiers.
- Protected, CLI-only schema/bootstrap commands; no web-accessible installer.

### Verification

- Against the MariaDB credentials in `.env.test`, a clean schema creation succeeds on MariaDB 10.6 syntax.
- Publishing the fixture twice is idempotent and produces identical reference row counts.
- A failed publication rolls back without exposing a partially updated active snapshot.
- Reconciliation checks compare exported SQLite and imported MariaDB counts and representative identifiers.
- MariaDB can answer the vote-search and date-valid party-member queries required by the wizard.

## Milestone 6 — Deployable PHP shell, security baseline, and Google authentication

### Goal

Build the framework-free deployable application foundation under `site/` and implement secure Google-only authentication.

### Required guidance

Read `.agents/skills/GOOGLEAUTH.md` completely before implementation.

### Outputs

- `site/index.php` as the deployable entry point and a clear framework-free PHP request structure.
- Pinned local Bootstrap 5 and Bootstrap Icons assets.
- `.env` loader, configuration validation, PDO connection factory, consistent JSON response format, and error handling.
- Apache `.htaccess` routing/security rules denying access to secrets, backend internals, database scripts, logs, and private storage.
- Secure session and CSRF implementation.
- `GET /api/auth-config`, `POST /api/google-login`, session-status, and logout endpoints.
- Server-side Google JWT verification with JWKS and a test-injected verifier.
- German signed-out/signed-in navigation and visible theme switch with persisted system/light/dark preference.

### Verification

- PHP syntax checks pass across all PHP files.
- Integration tests prove Google login creates, reuses, and safely links a user by verified unique email.
- Invalid signature, audience, issuer, expiration, unverified email, missing credential, unavailable keys, and disabled configuration return stable safe errors.
- Session ID changes on login; logout invalidates authenticated state; CSRF failures reject mutations.
- Direct HTTP requests cannot retrieve `.env`, backend PHP source, private storage, logs, or database scripts.
- Playwright checks signed-out and signed-in shells at desktop/mobile widths in light and dark modes.

## Milestone 7 — Public catalogue and personal insight management

### Goal

Implement the public landing catalogue and authorization-correct insight lifecycle before building the full evidence wizard.

### Outputs

- Public insight accordion list on both signed-out and signed-in landing pages.
- Separate signed-in "Meine Insights" list showing the owner's draft, unlisted, and public work.
- Create, read, update, visibility-change, and delete/archive behavior with server-side ownership checks.
- Non-guessable unlisted share links.
- Pagination or incremental loading with deterministic ordering.
- Empty, loading, error, and no-results states in German.

### Verification

- Public listings contain public insights from all users and exclude draft/unlisted insights.
- Owners see and edit all their own states; other users cannot mutate or retrieve drafts.
- An unlisted insight is reachable by its share URL but absent from all public lists and search-engine indexing signals.
- Guessing an insight ID or changing an owner field cannot bypass authorization.
- Accordion controls work by keyboard and expose correct accessible state.
- Playwright visual baselines cover empty and populated catalogues on desktop/mobile in light/dark modes.

## Milestone 8 — Five-step insight wizard and parliamentary evidence search

### Goal

Allow an authenticated user to assemble the parliamentary portion of an insight through the agreed guided workflow, with the vote step serving as a responsive analytical workspace for changing the member cohort, comparing Yes/No behavior, and identifying outliers.

### Required guidance

Read `.agents/skills/STEPPER.md` completely before implementation.

### Outputs

- Five-step German wizard: Rahmen, Mitglieder, Abstimmungen, Einordnung, Prüfen.
- Country/chamber/period/party selection, with Switzerland as the only enabled country.
- Member selection based on historical formal-party membership intersecting the chosen period, with faction context shown separately.
- A sticky member-cohort control in the `Abstimmungen` step with member search, select-all, deselect/reselect, selected counts, and reset to the Step 2 entry selection.
- One shared member-selection state synchronized between Steps 2 and 3, with the latest deliberate Step 2 selection becoming the next Step 3 reset baseline.
- Live recalculation of vote direction, participation denominator, tallies, cohesion, outlier indicators, filters, and totals whenever the cohort changes, without losing search state or selected evidence.
- Desktop Yes/No juxtaposition using full-width cards within balanced green/red columns, plus a neutral Split/non-directional view; accessible segmented result views on mobile.
- Vote search by affair/vote identifiers, title/text, date, chamber, official topic, reviewed classification, and vote type.
- Full-text matching over exact questions, Yes/No meanings, summaries, descriptive text, topics/descriptors, named legislation, committees, and reviewed classifications, with representative highlighted match context and reliable exact-identifier lookup.
- Filters for active-cohort direction, unanimity/cohesion, selected member, chamber, date, vote type, topic, and reviewed classification, with clear labeling that direction refers to the selected cohort.
- Default prioritization of identifiable substantive/final votes without hiding other recorded types.
- Direction rules that use the selected cohort's Yes-versus-No majority, keep ties and non-directional participation neutral, exclude abstentions/absences from direction, and expose every denominator.
- Collapsed cards showing direction, title, identifiers, date/chamber/type, Yes/No/abstention tally, eligible and participating members, cohesion, classification badges, and a full-width evidence toggle.
- Vote-detail accordion/modal showing exact question, Yes/No meaning, type, source, aggregate result, limitations, matching text, and selected members grouped by choice, including no participation and no mandate on the vote date.
- Neutral `Abweichende Stimme` markers and an outlier summary reporting each selected member's evaluated votes, agreement with the cohort majority, and divergent choices.
- Persistent evidence tray for reviewing, reordering, and removing selected votes without losing the exploration state.
- Draft autosave or explicit safe draft saving, review summary, publication validation, and editing of existing insights.

### Verification

- The stepper synchronizes buttons/panels, supports direct navigation and Back/Next, and stacks correctly on mobile.
- Selecting a party and period returns only people whose membership overlaps that period; evidence displays membership valid on each vote date.
- Deselecting or re-selecting a member in Step 3 updates Step 2 and recomputes all affected results without a reload; resetting restores the cohort captured on the latest entry from Step 2.
- Fixture votes move deterministically among Yes, No, Split, and non-directional results as cohort membership changes, while abstentions and no-mandate cases retain correct labels and denominators.
- Search text, filters, scroll context, and evidence selections survive cohort changes. Evidence with no selected member having any recorded participation remains visible with a blocking publication warning rather than disappearing; abstention-only evidence remains valid and non-directional.
- The outlier view correctly identifies a member who voted against the current cohort majority and does not label ties or abstentions as divergent Yes/No votes.
- Known votes are found by direct identifier even without reviewed classification.
- A selected vote retains its immutable source identifier and snapshot/version reference.
- A validation failure opens and focuses the first invalid step/control and announces a grouped German error summary.
- Drafts can remain incomplete; public publication fails until required claim, scope, and evidence fields are present.
- Playwright exercises creation, interruption/resume, synchronized cohort changes, desktop and mobile Yes/No/Split views, outlier discovery, evidence retention, editing, vote inspection, validation, and successful publication in light and dark modes.

## Milestone 9 — Campaign-context attachments and safe presentation

### Goal

Let users add campaign material that contextualizes their interpretation without confusing it with parliamentary evidence.

### Outputs

- Campaign-context item types for validated image upload, YouTube URL, and ordinary external link.
- Attribution, label, description, source URL, and ordering fields.
- Protected storage and authorized streaming for uploaded images.
- Strict server-side MIME/decoded-image validation, size limits, generated names, and non-executable storage.
- Narrow YouTube URL parsing and safe rendering; no arbitrary HTML/embed submission.
- Clear UI labels distinguishing user-provided campaign context from official vote evidence.

### Verification

- Valid supported images upload and render; renamed scripts, SVG/script payloads, oversized files, and invalid image bytes are rejected.
- A user cannot attach, reorder, or delete another user's media.
- Supported YouTube URLs normalize to a safe video identifier; unsupported hosts render only as escaped ordinary links or are rejected according to the documented rule.
- User text and URLs cannot create stored XSS in catalogue accordions, modals, or the wizard.
- Deleting an unsaved upload and deleting/archive-processing an insight follows the documented file-retention policy.
- Playwright covers image, YouTube, and link context in both themes and mobile layout.

## Milestone 10 — End-to-end hardening and deployable MVP release

### Goal

Validate the complete system against real representative data and produce a deployment package that works within the hosting constraints.

### Outputs

- Complete German README deployment/runbook for PHP 8.4, Apache, MariaDB 10.6, Google Cloud client configuration, `.env`, database bootstrap, data publication, backup, and rollback.
- Production-safe Apache configuration, CSP, session-cookie policy, cache headers, and error behavior.
- Seeded deterministic end-to-end test dataset with at least two users and all insight visibility states.
- Playwright functional and reviewed visual suite for Chromium at representative desktop and mobile viewports, light and dark modes.
- Final data-quality and security checklist.
- MVP acceptance report in `PROJECT.md`, including exact checks run and known limitations.

### Verification

- Starting from a clean test database, one documented command sequence creates schema, publishes fixtures, starts the site, and passes PHP/API/Playwright tests.
- The full critical path passes: public catalogue → Google login through test verifier → create insight → select historically valid members → vary the cohort and inspect regrouped Yes/No/Split votes → identify an outlier → find/select evidence → add campaign context → save draft → create unlisted link → publish → see on signed-out catalogue → edit as owner.
- Drafts and unlisted insights never leak into public lists, HTML, API payloads, feeds, or predictable URLs.
- Visual diffs are reviewed at desktop/mobile and light/dark states with no unintended overflow, overlap, clipped controls, illegible contrast, or theme flash.
- The deployed `site/` contains every required runtime file and `site/index.php`, while secrets, test credentials, source snapshots, SQLite files, and development tooling are absent.
- A smoke deployment to the target Apache/PHP/MariaDB environment succeeds, or every host-specific incompatibility is documented with a tested resolution.

## Milestone 11 — AI filter foundation and safe OpenAI boundary

### Goal

Add a disabled-by-default, testable OpenAI integration boundary with versioned database prompts, strict structured responses, bounded configuration, and no dependency on live API calls during ordinary development or CI.

### Outputs

- Idempotent MariaDB tables for immutable/versioned AI prompt templates and privacy-preserving filter-run/cache metadata. Raw criteria and candidate text are not retained in operational logs.
- One seeded active German prompt template that treats user criteria and parliamentary fields as untrusted data, permits only candidate IDs, requires an empty result for no match, and forbids inventing facts or Yes/No semantics.
- Placeholder-only `.env.example` and `site/.env.example` settings for an explicit feature flag, server-side OpenAI API key, Responses endpoint, configurable model, timeouts, candidate/chunk limits, cache lifetime, and per-user rate limits.
- A small provider interface plus production Responses API client and deterministic test client. The API key never reaches HTML, JavaScript, logs, database rows, or API responses.
- Strict Structured Outputs schema for matched and ambiguous candidate IDs with short German reasons; refusals, incomplete responses, timeouts, malformed bodies, unknown IDs, duplicates, and oversized selections have stable application errors.
- Requests use separate developer/system and user-data messages, `store: false`, a privacy-preserving stable safety identifier, and no tools or outbound destinations beyond the configured Responses endpoint.

### Verification

- Pure PHP tests cover request construction, instruction/data separation, schema parsing, refusals, malformed responses, unknown-ID rejection, duplicate normalization, bounds, and secret redaction.
- Applying `site/database/schema.sql` twice remains idempotent and seeds exactly one active prompt version for the filter purpose.
- Local `.env.test` MariaDB smoke checks the new tables, active-template lookup, cache metadata, and guarded rate-limit accounting without making a paid API request.
- With the feature disabled or the key absent, the application makes no external call and returns a stable unavailable state.

## Milestone 12 — Hybrid retrieval, semantic selection, and audited API

### Goal

Turn a user's German selection criterion into a scalable, transparent filter across the current insight scope without sending the complete parliamentary database to the model.

### Outputs

- Owner-only, CSRF-protected Step 3 API accepting bounded criteria and the current selected-member cohort; the server derives authoritative scope and candidates rather than trusting client-supplied voting records.
- First structured model stage producing bounded German search terms, synonyms, exclusions, optional date constraints, and vote-type hints.
- MariaDB full-text/exact retrieval over the immutable active insight publication, chamber, period, and selected-member participation, producing a deterministic bounded candidate pool.
- Second structured model stage evaluating compact candidates in bounded chunks and returning matched/ambiguous IDs with reasons; server-side merging, validation, deduplication, and deterministic ordering.
- Candidate objects include only the immutable vote ID, affair/vote identifiers, date/type, title, exact question, explicit Yes/No meanings, and relevant official metadata. The prompt may not infer missing semantics.
- Per-user rate limiting, request timeout, candidate/result caps, criteria/candidate/prompt hashes, token/latency/status metadata, and cache reuse without storing raw criteria.
- AI results remain a private discovery result and never create evidence, classifications, claims, or public catalogue content.

### Verification

- MariaDB integration tests use the deterministic provider to verify scope/ownership, CSRF, cohort participation, full-text retrieval, exact identifiers, chunk merging, unknown-ID rejection, cache reuse, rate limits, and rollback/failure behavior.
- A representative German evaluation fixture covers clear matches, exclusions, negation, unrelated criteria, missing Yes/No semantics, ambiguous records, and prompt-injection-like user/candidate text.
- Local `.env.test` smoke tests the complete endpoint and full reference schema without external API charges.
- Repeated identical requests reuse the cache; changing publication, prompt version, model, criteria, cohort, or candidate content invalidates it.

## Milestone 13 — Optional Step 3 AI-selection modal

### Goal

Expose AI-assisted discovery as an optional, reversible selection mechanism that is clearly separate from official facts, reviewed classifications, deterministic filters, and the user's evidence choices.

### Required guidance

Read `.agents/skills/STEPPER.md` completely before implementation.

### Outputs

- A full-width `Mit KI eingrenzen` action in Step 3 opening an accessible Bootstrap modal.
- German explanatory copy labeling the feature as an experimental AI preselection rather than an official or reviewed classification.
- Criteria textarea, examples, current scope/cohort/candidate summary, explicit start button, progress/long-wait/cancellation feedback, and clear provider-processing disclosure.
- Preview groups for matching and ambiguous votes with short reasons, checkboxes, counts, and links/actions that inspect the existing vote details.
- `Als Filter anwenden`, `Verwerfen`, and close behavior. Closing or discarding changes nothing; applying creates only a removable Step 3 filter pill and never selects evidence automatically.
- Reopening preserves the current private modal result during the page session; changing scope/cohort or relevant search inputs clearly invalidates stale AI results.
- Empty, refusal, timeout, disabled, rate-limited, and provider-error states remain actionable and do not disturb deterministic filters or selected evidence.

### Verification

- Playwright holds deterministic AI requests open and verifies one request per action, disabled duplicate submission, progress, cancellation/close behavior, focus management, `aria-busy`, and cleanup.
- Playwright verifies apply/clear behavior, ambiguous-result handling, stale-result invalidation, cohort synchronization, retained evidence, and that AI results never auto-select evidence.
- Reviewed visual baselines cover populated, empty, and error modal states plus the applied filter pill on desktop/mobile in light/dark modes.
- Keyboard-only use, reduced motion, modal scrolling, long German criteria/reasons, and narrow viewports have no trapped focus, clipped actions, overlap, or horizontal overflow.

## Milestone 14 — AI-filter evaluation, privacy, deployment, and release acceptance

### Goal

Prove the complete feature against representative local data, document its cost/privacy/quality boundaries, and make production activation an explicit operational choice.

### Outputs

- Versioned German selection evaluation with expected required, forbidden, ambiguous, and empty outcomes across representative parliamentary records.
- Deployment documentation for creating an OpenAI API key, selecting/configuring the model, enabling the feature, setting quotas/timeouts, rotating the key, disabling the feature instantly, and checking safe operational metrics.
- Privacy copy stating which user criterion and public parliamentary fields are sent to OpenAI, that identities/uploads/unrelated insight content are excluded, and that the API's current data controls still apply.
- Production-safe logs containing request IDs, hashes, status, latency, token counts, model, and prompt version without API keys, raw criteria, candidate text, Google identity, or campaign material.
- Deployment audit rules excluding deterministic test providers/credentials and requiring placeholder-only AI configuration in the deployable tree.
- `PROJECT.md`, README, deployment checklist, and rollback notes updated with exact verification and known semantic/cost limitations.

### Verification

- `npm.cmd run verify:clean` passes from a reset `.env.test` MariaDB database with deterministic AI behavior and no paid API call.
- PHP/API/Playwright suites pass together, including existing catalogue, authentication, wizard, campaign-context, deployment, and split-dump behavior.
- Reviewed desktop/mobile light/dark visual baselines show the modal and applied filter without regressions elsewhere in Step 3.
- An optional explicitly enabled live-provider smoke test succeeds with a user-supplied development API key; it is skipped rather than silently using production credentials when no key is configured.
- Production remains disabled until the host has a private API key, explicit enable flag, accepted quota/model settings, updated privacy notice, and a successful target-host smoke test.

## Cross-cutting acceptance rules

These apply to every milestone:

- Keep official source data, derived classifications, review state, and user claims visibly and structurally separate.
- Preserve provenance and historical party/faction membership.
- Use prepared statements, transactions, server-side authorization, CSRF protection, safe output encoding, and bounded input.
- Keep public runtime code under `site/`; keep research tooling and tests outside it.
- Use full-width primary/form buttons by default, with deliberate compact exceptions for dense utility controls.
- Verify affected desktop/mobile and light/dark page states whenever UI changes materially.
- Update README and `PROJECT.md` in the same milestone as behavior changes.
- Do not add frameworks, unreviewed political claims, or another country's adapter without explicit scope approval.

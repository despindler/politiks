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

# CODEX.md

Project execution rules for Codex sessions

## 1. Role of this file

This file defines how Codex should work in this repository or workspace.

Priority order when information conflicts:

1. Running code and executable behavior
2. Tests, if they exist and are trustworthy
3. Schema and configuration in the repository
4. README.md
5. PROJECT.md or similar working notes
6. Other documentation

If documentation disagrees with code, treat the code as correct and update the documentation in the same work package when practical.

This file governs execution discipline, not product architecture.

---

## 2. Response Style Guidelines

When responding in chat:

- Use short paragraphs with clear section headings when useful.
- Use bullet lists sparingly and only for compact sets.
- Use numbered lists only for strictly ordered procedures.
- Avoid nested numbering.
- Prefer clarity and structure over verbosity.

---

## 3. Engineering Principles

### 3.1 Design Constraints

1. Modularity
   Keep components small and focused. Prefer clear boundaries over clever abstractions.

2. Separation of concerns
   Keep UI, request handling, domain logic, persistence, and infrastructure separated where the surrounding codebase allows it. Do not force architecture that is disproportionate to the project.

3. Encapsulation
   Hide internal implementation details. Expose the smallest stable surface necessary.

4. Orthogonality
   Features should compose without hidden coupling. New functionality should require minimal refactoring.

5. Determinism and reproducibility
   Any randomness must be controlled by explicit seeds or policies. Generated artifacts must be reproducible or clearly marked as non-deterministic when that matters.

6. Observability
   Prefer actionable logs, diagnostics, and error messages that help identify failures quickly.

---

### 3.2 Correctness and Safety

1. Validate inputs at boundaries
   Public entry points should validate inputs and fail clearly.

2. Fail loudly and clearly
   Avoid silent fallbacks unless they are explicitly part of the design.

3. Security basics by default
   No arbitrary code execution, no path traversal, no avoidable secret exposure, and no unnecessary privilege.

---

## 4. Prototype Bias and Clean Slate Rule

This repository should be treated as prototype-oriented unless explicitly stated otherwise.

Default rule:

Prefer clarity and minimalism over backward compatibility.

Therefore:

- Remove obsolete code instead of deprecating it.
- Refactor cleanly rather than layering compatibility adapters.
- Delete unused endpoints, classes, helpers, and assets.
- Do not preserve legacy behavior unless explicitly required.
- Do not accumulate alternative implementations of the same concept.
- Prefer reset or replacement over migration complexity during early-stage development unless persistence stability is explicitly required.

If a change invalidates an old design and there is no explicit instruction to maintain compatibility, remove the old design entirely.

Dead code must not remain in the repository.

---

## 5. Documentation and Traceability

### 5.1 Working Notes

Maintain durable working notes when the task is substantial enough to benefit from traceability.

Use `PROJECT.md` or an equivalent project note when one of the following is true:

- the work spans multiple sessions
- the work spans multiple components or applications
- the work introduces a new subsystem or significant refactor
- the user explicitly wants milestone tracking or auditability

Suggested contents:

1. Short project or task summary
2. Milestones or work packages
3. For each work package, when relevant:
   - Date
   - Goal
   - What changed
   - How to run
   - How to verify
   - Known issues and decisions
   - Next steps

For small changes, concise inline documentation updates may be sufficient and a dedicated `PROJECT.md` is not mandatory.

---

### 5.2 README.md is the entry point

Update `README.md` when a change affects any of the following:

1. How to run
2. How to verify or test
3. Configuration, environment variables, or setup assumptions
4. Public API, CLI, or user-facing behavior
5. Project structure or key concepts

README should remain concise and practical.

---

### 5.3 Keep Documentation in Sync

Behavior changes and documentation updates should happen in the same work package whenever practical.

Documentation drift is not acceptable as a steady state.

---

## 6. Work Package Workflow

Work should be executed as clear, bounded work packages.

For each substantial work package:

1. Define scope
   State the goal and concrete deliverables.

2. Implement the change
   Keep changes minimal, coherent, and aligned with engineering principles.

3. Add or update verification
   Add tests where the project supports testing. Otherwise use the strongest practical verification available.

4. Run verification
   Run the relevant test suite, affected subset, build, lint step, manual check, or equivalent validation that the project can realistically support.

5. Update documentation
   Update README and other relevant documentation when behavior or usage changed.

6. Record decisions when needed
   Update `PROJECT.md` or equivalent notes if the task is substantial enough to require traceability.

For small tasks, keep the same discipline in lighter form rather than manufacturing process overhead.

---

## 7. Testing and Verification Rules

### 7.1 Minimal High-Value Coverage

When adding tests, create a minimal, sound set of high-value checks covering:

- the primary success path
- one representative failure path
- non-trivial branching logic

Do not generate exhaustive or combinatorial matrices unless explicitly requested.

Prefer meaningful coverage over volume.

---

### 7.2 Deterministic Verification

- Tests and automated checks should be reproducible.
- Avoid unnecessary network dependence.
- Use fixtures, mocks, or controlled sample data where appropriate.
- Control randomness explicitly where relevant.

---

### 7.3 Practical Scope

Prefer:

- unit tests for pure logic
- integration tests for boundary flows
- end-to-end checks only for critical smoke coverage

If the project does not have a realistic automated test harness, do not invent a disproportionate one for a small task. Instead, perform the strongest practical verification and state what was and was not verified.

### 7.4 Browser and visual verification

For user-facing work, verify behavior at realistic desktop and mobile viewports. When the repository uses Playwright or an equivalent browser harness:

- combine semantic and behavioral assertions with a small set of reviewed visual snapshots;
- cover important visual modes such as light and dark themes when the application supports them;
- make fixtures, time, animation state, fonts, viewport, and data deterministic enough to avoid meaningless snapshot churn;
- inspect changed screenshots rather than updating baselines blindly; and
- keep visual tests focused on stable page states and critical components rather than snapshotting every incidental screen.

Visual snapshots supplement accessibility, interaction, and content assertions. They do not prove that a workflow works by themselves.

For accessible UI work, preserve semantic HTML, keyboard operation, visible focus, labels, useful error announcements, adequate contrast, and responsive reading order. Test the primary workflow with keyboard-accessible controls where practical.

---

## 8. Change Management Rules

### 8.1 Backward Compatibility

Backward compatibility is not required by default.

If compatibility must be preserved, it must be explicitly stated in the work scope.

Otherwise:

- refactor cleanly
- remove outdated APIs and behaviors
- update documentation accordingly

---

### 8.2 Versioning and Migrations

If persisted state exists:

1. Introduce explicit versioning once stabilization becomes important.
2. During early prototype stages, prefer clear reset instructions over complex migration layers.
3. Add migration or version-handling checks only when stabilization requires them.

---

### 8.3 Error Handling

Prefer consistent error structures within a given application or subsystem.

When defining or reshaping an error format, prefer:

- stable machine-readable code when appropriate
- human-readable message
- optional details field

Do not force a new global error format onto untouched legacy code without cause.

---

## 9. Code Quality Rules

1. Prefer clarity over cleverness.
2. Keep functions short and named by intent.
3. Remove duplication by extracting helpers where that improves readability.
4. Use type hints or typing features where the language and codebase support them.
5. Keep dependencies minimal and justified.
6. Centralize configuration where practical.
7. Remove unused imports, classes, functions, and assets promptly.
8. Do not leave commented-out code blocks.
9. Match the surrounding codebase style unless there is a clear reason to improve it.

---

### 9.1 Configuration and secrets

1. Keep credentials and secrets out of version control.
2. Commit a non-secret `.env.example` or equivalent that documents every required setting.
3. Use separate ignored configuration for automated tests when tests require real services.
4. Validate required runtime settings at startup and fail with an actionable error.
5. Never expose secrets through browser bundles, API error details, logs, screenshots, fixtures, or command output.
6. When an application must live entirely inside a public document root, explicitly deny web access to environment files, private storage, logs, database scripts, and other non-public runtime material.

---

## 10. Deliverables Checklist

A substantial work package is complete when:

1. Functionality works as specified.
2. Appropriate verification has been added or performed.
3. Relevant checks have been run, where feasible.
4. README or equivalent usage documentation has been updated if required.
5. Decisions and milestones have been recorded if the task warranted traceability.
6. Dead or obsolete code introduced by the change has been removed.

---

## 11. File and Structure Conventions

Do not assume every repository has a single root application or a standard layout.

When structure already exists:

- respect the existing organization
- extend it consistently
- avoid imposing framework-style layouts on small or legacy projects unless explicitly requested

When creating new structure, prefer clear and conventional naming such as:

- `src`, `app`, or equivalent for code
- `tests` for automated checks
- `docs` for supplementary documentation
- `scripts` for tooling
- `.agents/` for agent-facing guidance

---

## 12. Session Startup Routine

At the start of a session:

1. Read `CODEX.md` and any relevant context or project notes if they exist.
2. Inspect the relevant code and configuration before making assumptions.
3. Identify the next work package or propose one when the task is substantial.
4. Execute the change with the lightest process that still preserves clarity, verification, and traceability.
5. Apply the clean-slate rule unless explicitly instructed otherwise.

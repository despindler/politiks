# Reuse Guide: Chest Mechanism For The ISO 27001 Office Game

This document is guidance for Codex agents building a new training game about configuring secure computer work environments, preparing ISO/IEC 27001 audit documentation, and learning how auditors inspect such environments. It focuses on reusing the "reward chest" mechanism from Ba Ba Bank as a reusable game pattern.

The current Ba Ba Bank chest system is not a generic component package. It is a small, coherent pattern spread across SQL tables, PHP service functions, API routes, Bootstrap/plain-JS modal markup, CSS animations, PNG assets, and Playwright tests. Reuse the pattern and assets where useful, but adapt the domain names and payload fields to the new game's scenario, floor plan, assets, risks, evidence, and audit workflow.

## Standards Note

The official reference name is ISO/IEC 27001, not only "ISO 27001". As checked on 2026-06-09, the current official product page is `ISO/IEC 27001:2022` and ISO lists `ISO/IEC 27001:2022/Amd 1:2024` as an amendment. Use these links as edition anchors, but do not copy protected standard text into game code or seed data:

- https://www.iso.org/standard/27001
- https://www.iso.org/standard/88435.html

In the game, store short training explanations, licensed content references, and control identifiers separately from mechanics. A future agent should verify the target edition and any sector-specific medical/privacy requirements before authoring detailed compliance content.

## Source Components In Ba Ba Bank

Use these files as the reference implementation:

- `database/migrations/20260603_001_add_rewards.sql`
  - Adds persisted reward state and reward event queue tables.
  - Initializes existing users to their current state so historical activity does not create retroactive chests.
- `database/migrations/20260603_002_add_boss_management.sql`
  - Adds editable reward configuration in `reward_config`.
- `site/backend/rewards.php`
  - Contains reward trigger logic, event creation, lazy monthly trigger handling, per-customer achievement state, and the daily queue.
- `site/backend/database.php`
  - Contains `dbRewardState`, `dbSetRewardState`, `dbInsertRewardEvent`, `dbUnopenedRewardEvents`, `dbOpenRewardEvent`, `dbRewardOverview`, and reward configuration helpers.
- `site/backend/backend.php`
  - Exposes the relevant endpoints:
    - `GET /customers/me/rewards/daily`
    - `POST /customers/me/rewards/{id}/open`
    - `GET /boss/rewards`
    - `PUT /boss/rewards/config`
- `site/customer/index.html`
  - Contains the chest modal DOM: `#reward-modal`, `#reward-chest-button`, `#reward-chest-image`, `#reward-title`, `#reward-description`, `#reward-amount`, `#reward-next-button`.
- `site/app.js`
  - Contains the client state machine: `rewardQueue`, `rewardIndex`, `rewardOpened`, `loadDailyRewards`, `showRewardQueue`, `renderCurrentReward`, `openCurrentReward`, `nextReward`, and `rewardImage`.
- `site/styles.css`
  - Contains the visual treatment and animations: `.reward-modal`, `.reward-stage`, `.reward-chest-button`, `.reward-sparkles`, `chestArrival`, `chestFloat`, `chestOpen`, `rewardPulse`, `sparklePop`.
- `site/assets/rewards/`
  - Runtime chest PNGs:
    - `chest-closed.png`
    - `chest-open-gold.png`
    - `chest-open-crystals.png`
- `misc/chests/PNG/`
  - Source asset variants if the new game needs additional chest looks.
- `tests/rewards.spec.js`
  - API-level reward behavior tests.
- `tests/boss-management.spec.js`
  - Admin overview and reward configuration tests.
- `tests/frontend-smoke.spec.js`
  - Basic UI smoke coverage for management views.

## Core Pattern To Reuse

The important design is "server-created, persisted, auditable chest events", not "client-side loot animation".

Ba Ba Bank separates chest handling into three concepts:

- Event rows: `reward_events` records every chest that has been earned and whether it has been opened.
- State rows: `customer_reward_state` records per-user progress needed to decide whether a new chest should be created.
- Config rows: `reward_config` lets an admin tune or disable reward families without code changes.

Carry this separation into the new game:

- `chest_events`: the durable queue of unopened and opened chests.
- `player_chest_state` or `scenario_chest_state`: idempotency and milestone state.
- `chest_config`: editable feature flags, scoring values, milestone thresholds, and trigger settings.

The UI should only present and acknowledge chest events. The server should create chest events, compute score/maturity changes, attach evidence references, and decide whether a chest is allowed.

## Domain Adaptation For The New Game

In the new game, a chest should represent feedback, evidence, or audit insight earned by doing something meaningful in the simulated office. Avoid treating chests as random loot. For an ISO/IEC 27001 training game, deterministic and explainable rewards are more useful.

Recommended chest meanings:

- `configuration_success`: the player configured a computer, cloud service, storage device, or network component in a way that reduces risk.
- `evidence_ready`: the player prepared an audit artifact, such as an asset inventory entry, access review, backup proof, risk treatment note, or policy document.
- `risk_reduced`: the player closed or reduced a risk in the current scenario.
- `finding_discovered`: in auditor mode, the player found an issue that should be documented.
- `audit_readiness`: the office reached a scenario milestone, such as all workstations encrypted or all shared storage assigned an owner.
- `remediation_hint`: optional guided-learning chest when a player repeatedly misses a control or evidence expectation.

Suggested variants:

- `gold`: positive outcome, completed evidence, secure configuration.
- `crystals`: higher-value milestone, audit-ready package, or cross-control achievement.
- `warning`: auditor finding or risky misconfiguration discovered.
- `document`: policy/evidence template unlocked.
- `locked`: requirements met technically, but supporting evidence is missing.

Ba Ba Bank only has `gold` and `crystals`. Add variants only if the UI needs the distinction. Keep `chest_variant` as a stable string and map it to images/icons in one frontend function.

## Suggested Data Model

Prefer game-specific table names instead of copying banking names:

```sql
CREATE TABLE chest_events (
  id INT NOT NULL AUTO_INCREMENT,
  scenario_id INT NOT NULL,
  player_id INT NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'implementer',
  reward_key VARCHAR(64) NOT NULL,
  reward_type VARCHAR(64) NOT NULL,
  chest_variant VARCHAR(32) NOT NULL,
  title VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL,
  learning_feedback TEXT NULL,
  trigger_scope VARCHAR(128) NULL,
  location_id INT NULL,
  asset_id INT NULL,
  control_ref VARCHAR(64) NULL,
  evidence_id INT NULL,
  risk_id INT NULL,
  finding_id INT NULL,
  points_delta INT NOT NULL DEFAULT 0,
  maturity_delta DECIMAL(8,4) NOT NULL DEFAULT 0,
  earned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opened_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY chest_events_player_opened (player_id, scenario_id, opened_at, earned_at),
  KEY chest_events_asset (scenario_id, asset_id),
  KEY chest_events_control (scenario_id, control_ref)
);
```

Recommended state table:

```sql
CREATE TABLE chest_state (
  scenario_id INT NOT NULL,
  player_id INT NOT NULL,
  state_key VARCHAR(96) NOT NULL,
  state_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scenario_id, player_id, state_key)
);
```

Recommended config table:

```sql
CREATE TABLE chest_config (
  config_key VARCHAR(96) NOT NULL,
  config_value VARCHAR(255) NOT NULL,
  value_type VARCHAR(16) NOT NULL DEFAULT 'string',
  label VARCHAR(128) NOT NULL,
  description VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (config_key)
);
```

Add a uniqueness rule if a trigger must be earned only once:

```sql
-- Shape only: adapt syntax to the target database.
UNIQUE (scenario_id, player_id, reward_key, trigger_scope)
```

Use `trigger_scope` for idempotency. Examples:

- `workstation:12:disk_encryption_enabled`
- `cloud:3:mfa_enforced`
- `evidence:risk-register:v1-submitted`
- `room:reception:privacy-screen-complete`
- `audit:control-A.5.x:finding-documented`

Do not store the full ISO standard text in the database. Store references, mappings, training summaries, and links to licensed/source content as appropriate. The official standard reference is currently ISO/IEC 27001:2022 with Amendment 1:2024; agents should verify the applicable edition for the project before writing detailed compliance content.

## Event Payload Contract

Expose a stable API payload that is richer than Ba Ba Bank's money-focused payload:

```ts
type ChestEvent = {
  id: number;
  scenario_id: number;
  reward_key: string;
  reward_type: "configuration" | "evidence" | "risk" | "audit" | "milestone" | "hint";
  chest_variant: "gold" | "crystals" | "warning" | "document" | "locked";
  title: string;
  description: string;
  learning_feedback?: string;
  points_delta?: number;
  maturity_delta?: number;
  control_refs?: string[];
  evidence_refs?: number[];
  location_id?: number;
  asset_id?: number;
  risk_id?: number;
  finding_id?: number;
  earned_at: string;
};
```

For Ba Ba Bank compatibility, the frontend needs at minimum:

- `id`
- `chest_variant`
- `title`
- `description`
- a displayable value such as `amount`, `points_delta`, or `maturity_delta`
- an API endpoint that marks the event opened

## API Contract

Recommended game endpoints:

- `GET /api/scenarios/{scenarioId}/chests/unopened`
  - Returns unopened chests for the current player and scenario.
  - May run lazy trigger evaluation first, such as scenario milestone checks.
  - Orders by `earned_at ASC, id ASC`.
- `POST /api/scenarios/{scenarioId}/chests/{chestId}/open`
  - Marks one chest as opened for the current player.
  - Must verify ownership and scenario.
  - Should be idempotent from the user's perspective. Returning `true` only on the first update is fine, but repeated calls must not duplicate rewards or fail noisily.
- `POST /api/scenarios/{scenarioId}/actions/{actionId}/evaluate`
  - Applies a configuration/evidence/audit action and creates any resulting chests server-side.
- `GET /api/scenarios/{scenarioId}/audit/chests`
  - Admin/auditor/instructor overview of unopened, opened, recent events, and reward configuration.
- `PUT /api/admin/chests/config`
  - Updates enabled triggers, scoring values, and milestone thresholds.

Use session/authorization checks equivalent to Ba Ba Bank's customer/boss split:

- Player can open only their own chests.
- Instructor/auditor/admin can inspect scenario-level chest history.
- Audit-mode players should only receive finding/evidence chests relevant to their role.

## Backend Trigger Rules

Create chest events after meaningful state transitions. Keep trigger code deterministic and idempotent.

Examples for the physician-office scenario:

- Computer setup:
  - Disk encryption enabled on a workstation -> `configuration_success`, `asset_id`, `control_ref`, positive points.
  - Screen lock timeout set under the scenario threshold -> `configuration_success`.
  - Local admin removed from daily user account -> `risk_reduced`.
- Cloud service use:
  - MFA enabled for all users -> `audit_readiness` milestone.
  - Shared folder owner and access review documented -> `evidence_ready`.
  - Unrestricted public sharing discovered by auditor -> `finding_discovered`.
- Storage devices:
  - USB drive encrypted and assigned to owner -> `configuration_success`.
  - Unencrypted removable storage discovered -> `finding_discovered` or `warning`.
- Documentation:
  - Asset inventory covers all devices on the floor plan -> `evidence_ready`.
  - Risk register contains owner, treatment, and review date for all high risks -> `audit_readiness`.
  - Backup restore test evidence uploaded -> `evidence_ready`.
- Audit workflow:
  - Auditor checks an asset and records sufficient evidence -> `finding_discovered` or `audit_readiness`.
  - Auditor requests missing documentation -> `warning` chest with remediation feedback.

State keys should encode the milestone, not a UI event:

- `asset:12:disk_encryption_rewarded`
- `scenario:7:all_workstations_encrypted`
- `evidence:asset_inventory:coverage_level`
- `audit:round:1:all_high_risks_reviewed`

The Ba Ba Bank rule to preserve: do not suppress later chests just because an earlier daily/entry check found none. Unopened persisted events are the queue.

## Frontend Component Specification

Ba Ba Bank uses a simple modal state machine:

```js
let rewardQueue = [];
let rewardIndex = 0;
let rewardOpened = false;
```

Port this as a framework-neutral `ChestQueue` concept:

- `loadUnopenedChests()`
  - Fetch unopened events.
  - If non-empty, call `showChestQueue(events)`.
- `showChestQueue(events)`
  - Store queue.
  - Set index to zero.
  - Render first chest closed.
  - Show modal or overlay.
- `renderCurrentChest()`
  - Reset `opened` to false.
  - Show step indicator, title, generic prompt, closed image.
  - Hide the next/continue button.
  - Enable the chest button.
- `openCurrentChest()`
  - Ignore if no current chest or already opened.
  - Set `opened` immediately to prevent double clicks.
  - Disable the button.
  - Switch image/variant to opened.
  - Reveal feedback, points, evidence links, and control references.
  - POST the open acknowledgement.
  - Show next/continue.
- `nextChest()`
  - Advance queue.
  - Render next chest or close overlay.
  - Refresh scenario score, floor-plan markers, evidence panel, and audit summary.

For a floor-plan game, use two layers:

- Local contextual feedback:
  - A small chest marker appears near a workstation, storage cabinet, server closet, or cloud-service icon when an action earns a chest.
  - Clicking/tapping the marker opens that chest in the shared modal.
- Global queue:
  - A top-bar chest inbox shows unopened count.
  - On scenario entry or after major actions, show the queue if it contains high-value or required-learning chests.

Keep the modal generic. The floor plan, device detail panels, and evidence editors should not contain separate chest-opening logic.

## Suggested UI Elements

Minimum reusable DOM structure:

```html
<div class="modal fade reward-modal" id="chest-modal" tabindex="-1" aria-labelledby="chest-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content reward-modal-content">
      <div class="modal-body text-center p-4 p-sm-5">
        <div class="reward-stage" aria-live="polite">
          <div class="reward-sparkles" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
          </div>
          <button id="chest-button" class="reward-chest-button" type="button" aria-label="Open reward chest">
            <img id="chest-image" src="/assets/rewards/chest-closed.png" alt="">
          </button>
        </div>
        <div class="reward-copy">
          <p id="chest-step" class="mini-badge reward-step mx-auto mb-3">Chest</p>
          <h1 class="h3 mb-2" id="chest-title">Security Milestone</h1>
          <p id="chest-description" class="text-secondary mb-3">Open the chest.</p>
          <div id="chest-value" class="reward-amount mb-3"></div>
          <div id="chest-feedback" class="text-start"></div>
          <button id="chest-next-button" type="button" class="btn btn-primary is-hidden">Continue</button>
        </div>
      </div>
    </div>
  </div>
</div>
```

If the new game is not Bootstrap-based, keep the structure and state machine but replace Bootstrap modal calls with the local dialog/component system.

Accessibility requirements:

- The chest button must be a real `button`, not a clickable image.
- Disable the button after opening to prevent duplicate POSTs.
- Use `aria-live="polite"` for the stage or feedback area.
- Move focus to the continue button after a chest opens.
- Do not rely on color alone to distinguish positive, warning, and evidence chest types.

## Visual Asset Guidance

Reusable Ba Ba Bank runtime assets:

- `site/assets/rewards/chest-closed.png`
- `site/assets/rewards/chest-open-gold.png`
- `site/assets/rewards/chest-open-crystals.png`

For the ISO 27001 game, consider adding office/security themed variants:

- `chest-open-evidence.png`: documents, clipboard, or checkmarks.
- `chest-open-warning.png`: amber light or finding marker.
- `chest-open-audit.png`: badge, magnifier, or report.
- `chest-locked.png`: earned technically, but evidence is missing.

Optimize PNGs before shipping. The Ba Ba Bank README notes that current chest images were copied from `misc/chests` without an image optimization step.

## Admin, Auditor, And Instructor Overview

Ba Ba Bank's boss rewards view is a useful pattern for the new game's instructor/auditor screen:

- Config panel:
  - Enable/disable trigger families.
  - Tune scoring and milestone thresholds.
  - Select training mode strictness.
- Unopened overview:
  - Player/scenario.
  - Count of unopened chests.
  - Total points or maturity deltas waiting to be acknowledged.
  - Latest earned time.
- Event log:
  - Player.
  - Scenario.
  - Asset/location.
  - Chest title.
  - Control reference.
  - Evidence/finding link.
  - Opened vs unopened.

For auditor mode, the event log should support report generation:

- Findings discovered.
- Evidence inspected.
- Controls sampled.
- Risks accepted or remediated.
- Missing documentation.

Do not have the game claim real certification. Phrase outcomes as training results, audit readiness, or simulated auditor judgment unless the product has a real certification workflow.

## Testing Requirements

Port the spirit of `tests/rewards.spec.js`:

- Creating a secure configuration creates the expected chest event.
- Creating documentation evidence creates the expected chest event.
- Crossing a scenario milestone creates exactly one milestone chest.
- Multiple earned chests are returned in stable order.
- Opening one chest removes only that chest from the unopened queue.
- A same-day or same-session empty check does not hide chests earned later.
- Opening a chest for another player or scenario is rejected.
- Repeated open requests do not duplicate points or feedback.
- Config toggles disable only the intended trigger family.
- Lazy evaluations run once per relevant period or scenario milestone.

Frontend smoke tests:

- Floor-plan computer click opens the device configuration panel.
- Completing a valid action creates or reveals a chest marker.
- Chest modal shows closed image first.
- Opening swaps to the correct opened variant and shows feedback.
- Next button advances through multiple chests.
- Closing the final chest refreshes score, audit readiness, and evidence panels.
- Mobile viewport: chest image, feedback, and continue button do not overlap.

## Implementation Checklist For Future Agents

1. Identify the target stack.
   - Same PHP/plain-JS stack: copy and rename the Ba Ba Bank files in small pieces.
   - React/Vue/Svelte/etc.: port the concepts, not the raw DOM code.
2. Add persistent tables first.
   - `chest_events`
   - `chest_state`
   - `chest_config`
   - migration plus fresh schema/seed updates if the repo uses both paths.
3. Implement server-only trigger creation.
   - No client-side score or chest creation authority.
   - Use idempotent state keys or unique trigger scopes.
4. Implement unopened queue and open acknowledgement endpoints.
   - Always scope by current player and scenario.
   - Keep opened rows for audit/history.
5. Implement the shared chest queue UI.
   - One modal/overlay.
   - One image mapping function.
   - One queue state machine.
6. Integrate with floor-plan interactions.
   - Device/cloud/storage actions call server evaluation.
   - Returned or later-fetched chests appear as contextual markers and/or inbox count.
7. Add instructor/auditor overview.
   - Recent chest events.
   - Unopened counts.
   - Config form.
8. Add tests before broad content work.
   - Prove event creation, queue behavior, open behavior, and authorization.

## Pitfalls To Avoid

- Do not delete chest rows when opened. Set `opened_at`.
- Do not make the chest reward the only place where score changes are stored. Store score/maturity changes in authoritative game state or derive them from durable event rows.
- Do not let the browser create chests directly.
- Do not hardcode ISO control text into code. Use content data with edition metadata and verify licensing.
- Do not make chests random for core compliance learning outcomes. The player must understand why a security action helped or why evidence was insufficient.
- Do not couple the queue to "daily login" semantics. In the new game, queue checks should happen on scenario entry, after actions, and from a chest inbox.
- Do not mix implementer and auditor role rewards without a role field. The same office state can produce different learning events depending on role.
- Do not block later chests because an earlier check returned none.
- Do not expose chests across scenarios, tenants, classrooms, or users.

## Minimal Agent Prompt To Introduce Chests In The New Game

When asking a Codex agent to implement the first version, provide this summary:

> Reuse the Ba Ba Bank chest pattern from `.doc/REUSE_GAME_CHEST.md`. Implement durable server-created `chest_events`, `chest_state`, and `chest_config` tables. Add endpoints to fetch unopened chests for the current player/scenario and mark a chest opened. Add a shared chest queue modal using the Ba Ba Bank state machine and assets. Trigger deterministic chests from secure configuration, evidence preparation, risk reduction, audit findings, and scenario milestones. Scope all events by player and scenario, keep opened rows for audit history, and add tests for event creation, queue order, opening, idempotency, and authorization.
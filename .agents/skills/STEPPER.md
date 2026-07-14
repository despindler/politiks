# Process Stepper Specification

This document specifies the process stepper pattern so a Codex agent can recreate the same visual guidance component in other applications.

The implementation is a compact, horizontal, chevron-style stepper made from ordinary buttons, CSS pseudo-elements, and a small amount of JavaScript that toggles the active step.

## Purpose

Use this stepper for short, linear, multi-step forms where users benefit from knowing:

- how many steps exist
- which step they are currently editing
- what each step is about
- that the final step is a review/save step

The stepper is visual guidance and direct navigation. It is not a progress meter and does not mark steps complete in the current application.

## Visual Model

Desktop layout:

- One full-width horizontal bar.
- Equal-width step segments.
- Each segment contains a circular number, a bold step title, an optional icon, and a short caption.
- Segments are separated by right-pointing chevron tips.
- The active segment has:
  - a soft tinted background
  - dark title text
  - a teal numbered circle
  - a teal inset bottom rule
  - the bottom rule continuing through the active chevron tip

Mobile layout:

- The stepper stacks vertically.
- Chevron tips are removed.
- Steps are separated by simple horizontal borders.
- Captions can wrap.

## Dependencies

The current app uses:

- Bootstrap 5 nav classes for baseline layout semantics: `nav`, `nav-item`, `nav-link`, spacing utilities.
- Bootstrap Icons for step title icons.
- Plain CSS for the chevron stepper shape.
- Plain JavaScript for step activation.

The pattern does not require Bootstrap. In a non-Bootstrap app, keep the same DOM structure and copy the CSS rules without the Bootstrap utility classes, replacing them with project-local equivalents.

## Design Tokens

Define these project-level colors or map them to an existing design system:

```css
:root {
    --stepper-ink: #1d252c;
    --stepper-muted: #62707c;
    --stepper-line: #dbe2e8;
    --stepper-accent: #276b73;
    --stepper-bg: #ffffff;
    --stepper-step-bg: #f9fbfb;
    --stepper-step-active-bg: #eef8f4;
}
```

In this example application the equivalent variables are named `--survey-ink`, `--survey-muted`, `--survey-line`, and `--survey-accent`.

## Required DOM Structure

Place the stepper inside the form or wizard container, immediately before the step panels.

Each stepper button must correspond by index to exactly one `.wizard-step` panel. The first button and first panel start with `active`.

```html
<form data-wizard novalidate>
    <ul class="nav wizard-progress mb-4" role="tablist" aria-label="Use case steps">
        <li class="nav-item">
            <button class="nav-link active" type="button" data-step-target aria-current="step">
                <span class="step-index">1</span>
                <span class="step-copy">
                    <span class="step-title">
                        <i class="bi bi-tags me-1" aria-hidden="true"></i>Category
                    </span>
                    <span class="step-caption">Choose the usage type.</span>
                </span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" type="button" data-step-target>
                <span class="step-index">2</span>
                <span class="step-copy">
                    <span class="step-title">
                        <i class="bi bi-card-text me-1" aria-hidden="true"></i>Basic details
                    </span>
                    <span class="step-caption">Describe the concrete case.</span>
                </span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" type="button" data-step-target>
                <span class="step-index">3</span>
                <span class="step-copy">
                    <span class="step-title">
                        <i class="bi bi-sliders me-1" aria-hidden="true"></i>Details and cost
                    </span>
                    <span class="step-caption">Add context and estimates.</span>
                </span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" type="button" data-step-target>
                <span class="step-index">4</span>
                <span class="step-copy">
                    <span class="step-title">
                        <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Review
                    </span>
                    <span class="step-caption">Check and save.</span>
                </span>
            </button>
        </li>
    </ul>

    <div class="d-none" data-validation-alert role="alert"></div>

    <section class="wizard-step active">
        <!-- Step 1 fields -->
        <button type="button" data-next>Next</button>
    </section>

    <section class="wizard-step">
        <!-- Step 2 fields -->
        <button type="button" data-prev>Back</button>
        <button type="button" data-next>Next</button>
    </section>

    <section class="wizard-step">
        <!-- Step 3 fields -->
        <button type="button" data-prev>Back</button>
        <button type="button" data-next>Review</button>
    </section>

    <section class="wizard-step">
        <!-- Review summary -->
        <button type="button" data-prev>Back</button>
        <button type="submit">Save</button>
    </section>
</form>
```

Implementation requirements:

- Use `type="button"` on stepper buttons and Next/Back buttons so they do not submit the form.
- Keep `.step-index`, `.step-copy`, `.step-title`, and `.step-caption` as direct descendants in this structure because the CSS expects them.
- Keep step titles short enough to fit in one line on desktop.
- Use `aria-hidden="true"` on decorative icons.
- Use a clear `aria-label` on the stepper list, for example `Use case steps`.
- For new projects, update `aria-current="step"` or `aria-selected` when the active step changes. The existing app visually toggles `.active`; adding ARIA state is recommended for portability.

## CSS Specification

This is the reusable version of the example app's stepper CSS. Rename variables as needed.

```css
.wizard-progress {
    display: flex;
    border: 1px solid var(--stepper-line);
    border-radius: .5rem;
    background: var(--stepper-bg);
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(16, 36, 44, .04);
}

.wizard-progress .nav-item {
    flex: 1 1 0;
    min-width: 0;
    position: relative;
}

/* Required for chevrons to overlap cleanly from left to right.
   Add more nth-child rules, or set --step-z inline, when using more than 4 steps. */
.wizard-progress .nav-item:nth-child(1) { z-index: 4; }
.wizard-progress .nav-item:nth-child(2) { z-index: 3; }
.wizard-progress .nav-item:nth-child(3) { z-index: 2; }
.wizard-progress .nav-item:nth-child(4) { z-index: 1; }

.wizard-progress .nav-link {
    --step-bg: var(--stepper-step-bg);
    position: relative;
    width: 100%;
    height: 100%;
    min-height: 74px;
    padding: .85rem 1rem .85rem 1.35rem;
    border: 0;
    border-radius: 0;
    color: var(--stepper-muted);
    background: var(--step-bg);
    text-align: left;
    display: flex;
    align-items: center;
    gap: .75rem;
    overflow: visible;
}

.wizard-progress .nav-item:not(:last-child) .nav-link::before,
.wizard-progress .nav-item:not(:last-child) .nav-link::after {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    clip-path: polygon(0 0, 100% 50%, 0 100%);
    pointer-events: none;
}

.wizard-progress .nav-item:not(:last-child) .nav-link::before {
    right: -22px;
    width: 22px;
    background: var(--stepper-line);
    z-index: 2;
}

.wizard-progress .nav-item:not(:last-child) .nav-link::after {
    top: 1px;
    bottom: 1px;
    right: -20px;
    width: 20px;
    background: var(--step-bg);
    z-index: 3;
}

.wizard-progress .nav-item:not(:first-child) .nav-link {
    padding-left: 2rem;
}

.wizard-progress .nav-link.active {
    --step-bg: var(--stepper-step-active-bg);
    color: var(--stepper-ink);
    box-shadow: inset 0 -4px 0 var(--stepper-accent);
}

.wizard-progress .nav-item:not(:last-child) .nav-link.active::after {
    background: linear-gradient(
        to bottom,
        var(--step-bg) calc(100% - 4px),
        var(--stepper-accent) calc(100% - 4px)
    );
}

.wizard-progress .step-index {
    flex: 0 0 auto;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: #a6b3bf;
    font-weight: 700;
    font-size: .88rem;
}

.wizard-progress .nav-link.active .step-index {
    background: var(--stepper-accent);
}

.wizard-progress .step-copy {
    min-width: 0;
}

.wizard-progress .step-title {
    display: block;
    color: var(--stepper-ink);
    font-weight: 700;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wizard-progress .step-caption {
    display: block;
    margin-top: .15rem;
    font-size: .8rem;
    color: var(--stepper-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wizard-step {
    display: none;
}

.wizard-step.active {
    display: block;
}

@media (max-width: 767.98px) {
    .wizard-progress {
        flex-direction: column;
    }

    .wizard-progress .nav-link {
        min-height: 62px;
        padding: .8rem 1rem;
    }

    .wizard-progress .nav-item:not(:first-child) .nav-link {
        padding-left: 1rem;
    }

    .wizard-progress .nav-item:not(:last-child) .nav-link::before,
    .wizard-progress .nav-item:not(:last-child) .nav-link::after {
        display: none;
    }

    .wizard-progress .nav-item:not(:last-child) .nav-link {
        border-bottom: 1px solid var(--stepper-line);
    }

    .wizard-progress .step-caption {
        white-space: normal;
    }
}
```

## How The Chevron Shape Works

The chevron separator is made with two clipped pseudo-elements on every step except the last:

- `::before` is the larger triangle, 22px wide, colored with the border color. It creates the visible separator edge.
- `::after` is the smaller triangle, 20px wide, colored with the step background. It sits on top of `::before`, offset by 1px top/bottom, so only the border edge remains visible.
- Both use `clip-path: polygon(0 0, 100% 50%, 0 100%)` to make a right-pointing triangular tip.
- The parent `.wizard-progress` uses `overflow: hidden`, so the first and last outer corners stay clipped to the rounded rectangle.
- Each earlier step has a higher `z-index` than the following step so its chevron can overlap the next segment cleanly.

The active step bottom rule uses `box-shadow: inset 0 -4px 0 var(--stepper-accent)`. For active steps that have a chevron, `::after` uses a two-color vertical gradient so the bottom 4px of the chevron also becomes accent colored.

## JavaScript Behavior

The current app keeps JavaScript deliberately simple:

- Collect all step panels with `.wizard-step`.
- Collect all stepper buttons with a data attribute.
- Store the current index.
- Clamp requested indices between first and last.
- Toggle `.active` on the matching step panel and stepper button.
- Wire Next, Back, and direct stepper clicks to the same `showStep` function.
- On submit validation failure, open the step that contains the first missing required field and focus that field.

Reusable initializer:

```js
function initWizardStepper(root, options = {}) {
    const stepSelector = options.stepSelector || '.wizard-step';
    const targetSelector = options.targetSelector || '[data-step-target]';
    const nextSelector = options.nextSelector || '[data-next]';
    const prevSelector = options.prevSelector || '[data-prev]';
    const onStepShown = options.onStepShown || (() => {});

    const steps = Array.from(root.querySelectorAll(stepSelector));
    const tabs = Array.from(root.querySelectorAll(targetSelector));
    let current = 0;

    if (steps.length === 0 || tabs.length === 0 || steps.length !== tabs.length) {
        throw new Error('Wizard stepper requires matching step panels and step targets.');
    }

    function showStep(index) {
        current = Math.max(0, Math.min(index, steps.length - 1));

        steps.forEach((step, i) => {
            step.classList.toggle('active', i === current);
        });

        tabs.forEach((tab, i) => {
            const active = i === current;
            tab.classList.toggle('active', active);
            tab.toggleAttribute('aria-current', active);
        });

        onStepShown(current, steps[current], tabs[current]);
    }

    root.querySelectorAll(nextSelector).forEach((button) => {
        button.addEventListener('click', () => showStep(current + 1));
    });

    root.querySelectorAll(prevSelector).forEach((button) => {
        button.addEventListener('click', () => showStep(current - 1));
    });

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => showStep(index));
    });

    showStep(0);

    return { showStep, getCurrentStep: () => current };
}

document.querySelectorAll('[data-wizard]').forEach((wizard) => {
    initWizardStepper(wizard, {
        onStepShown: () => {
            // Optional: update review summaries, conditional fields, or validation UI.
        },
    });
});
```

If a page has multiple different wizards, either:

- use the same initializer with different root elements, or
- pass different selectors such as `[data-needs-step-target]`, `[data-needs-next]`, and `[data-needs-prev]`.

The current example app uses two separate wizard scripts because the forms have different review and validation logic, but the stepper state logic is the same.

## Shared State Across Steps

Some workflows let a later step revisit and modify choices first introduced in an earlier step. For example, a setup step may select a cohort while a later exploration step exposes the same selection next to derived results. Treat these controls as two views of one shared state, not as independent form values.

Requirements for this pattern:

- Keep one authoritative in-memory/form state for the shared selection.
- Render controls in every affected step from that state and synchronize them immediately after a change.
- Recompute all dependent summaries, filters, validation, and derived results after the shared state changes.
- Preserve unrelated work such as search text, scroll context, and explicit item selections during recomputation.
- Do not silently delete downstream selections that have become incompatible. Retain and flag them so the user can resolve the conflict.
- If a reset action is useful, capture and label its baseline explicitly, such as the selection present when the exploration step was entered.
- When the user returns to the original step and deliberately changes the source selection, establish a new baseline on the next entry.
- Announce material result-count or validation changes through a polite live region without moving focus unexpectedly.
- Debounce expensive server-backed recomputation while keeping checkbox/chip state responsive.

Test direct navigation as well as Next/Back navigation. The same shared selection and dependent results must appear regardless of which step's controls made the latest change. Avoid duplicating hidden inputs as competing sources of truth.

## Validation Pattern Used In This Example App

The stepper does not block movement between steps. Users may click any step at any time.

Required-field validation happens on submit:

1. Find missing visible/enabled required fields.
2. Determine the `.wizard-step` containing the first missing field.
3. Call `showStep(stepIndex)`.
4. Render an alert above the panels listing missing fields grouped by step title.
5. Scroll the alert into view.
6. Focus the first missing field.

This keeps navigation lightweight while still making validation failures actionable.

When adapting this pattern, validation must ignore:

- disabled fields
- hidden fields
- fields in inactive conditional category groups
- duplicate radio buttons from the same required radio group

## Step Count Rules

The current CSS includes `nth-child` z-index rules for four steps. For a different number of steps, update the z-index strategy.

For a fixed number of steps, add explicit rules:

```css
.wizard-progress .nav-item:nth-child(1) { z-index: 5; }
.wizard-progress .nav-item:nth-child(2) { z-index: 4; }
.wizard-progress .nav-item:nth-child(3) { z-index: 3; }
.wizard-progress .nav-item:nth-child(4) { z-index: 2; }
.wizard-progress .nav-item:nth-child(5) { z-index: 1; }
```

For a dynamic stepper, set a custom property in generated markup:

```html
<li class="nav-item" style="--step-z: 5">...</li>
<li class="nav-item" style="--step-z: 4">...</li>
```

```css
.wizard-progress .nav-item {
    z-index: var(--step-z, 1);
}
```

## Content Guidelines

Use concise step content:

- Step title: 1 to 3 words when possible.
- Caption: short verb phrase describing the user's task.
- Icon: optional but useful for scanning.
- Number: always present.

Recommended sequence for data-entry wizards:

1. Category or setup choice.
2. Basic details.
3. Detailed inputs, estimates, or configuration.
4. Review and save.

Avoid using the stepper for:

- long navigation menus
- more than 5 or 6 steps without careful responsive design
- workflows where the next step is unknown or strongly branching
- decorative progress bars without actual panels

## Accessibility Requirements

Minimum:

- Buttons must be keyboard-focusable native `<button>` elements.
- Buttons must use `type="button"`.
- The stepper list must have `role="tablist"` or a clear navigation label.
- The stepper must have an `aria-label` that describes the workflow.
- Icons must be decorative with `aria-hidden="true"` unless the icon conveys unique information.
- The active visual state must not rely only on color; this pattern also uses a filled circle and bottom rule.

Recommended additions for new projects:

- Add `aria-current="step"` to the active button.
- If using true tab semantics, use `role="tab"` on buttons, `role="tabpanel"` on panels, `aria-selected`, `aria-controls`, and matching panel IDs.
- Preserve visible focus outlines. Do not remove browser focus styles unless replacing them with an equally visible custom style.
- Announce validation failures in a `role="alert"` container.

## Implementation Checklist

Before handing off a stepper implementation, verify:

- The active button and active panel are synchronized.
- Next and Back clamp at the first and last steps.
- Direct clicking a stepper segment opens the matching panel.
- The chevron separators line up and do not leave gaps.
- The active bottom rule continues through the active chevron.
- Long titles and captions truncate on desktop.
- Mobile stacks vertically and hides chevrons.
- Buttons do not submit the form accidentally.
- Validation opens the first step with missing required data.
- The component works with keyboard navigation and visible focus.

## Common Pitfalls

- Missing `position: relative` on `.nav-link` breaks pseudo-element positioning.
- Missing `overflow: hidden` on `.wizard-progress` lets chevrons protrude outside rounded corners.
- Missing z-index ordering causes chevrons to render under later steps.
- Forgetting extra left padding on non-first steps makes text collide with the previous chevron.
- Using anchors with `href="#"` causes page jumps; use buttons.
- Letting desktop captions wrap makes the stepper height inconsistent; truncate on desktop and allow wrapping only on mobile.
- Adding more steps without updating z-index rules causes separator layering bugs.
- Hiding a conditional field without disabling or excluding it from validation causes false validation failures.

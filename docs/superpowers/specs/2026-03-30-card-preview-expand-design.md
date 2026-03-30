# Card Preview Expand Design

**Date:** 2026-03-30
**Status:** Approved
**Scope:** Add consistent collapsed card previews with inline expand/collapse for main thought text on the signed-in idea home and stream views.

## Overview

The signed-in idea home and stream currently render the full main thought text for every card. This makes scan depth inconsistent when some thoughts are much longer than others.

The new behavior should cap the visible main thought text to roughly 15 lines by default, while letting the user expand any long card inline to read the full text without leaving the feed.

## Goals

- Make card heights more consistent while scanning the signed-in home feed and stream
- Keep long thoughts readable without forcing navigation to the detail page
- Apply one shared interaction pattern to both feeds
- Preserve existing detail-link, editing, comment, and section-preview behavior
- Keep expansion state only for the current page view

## Non-Goals

- Changing the public logged-out marketing homepage
- Changing comment preview or section preview truncation
- Persisting expanded state across reloads or browsers
- Reworking stream/index card layout beyond the preview behavior
- Introducing a heavier front-end dependency

## Current State

The signed-in idea home uses `resources/views/idea/index_thought_cards.blade.php`.

The stream uses `resources/views/idea/stream_thoughts.blade.php`.

Both render main thought text through the shared partial `resources/views/idea/partials/editable_thought_content.blade.php`, but neither surface currently constrains the visible height of the main thought body. As a result, long thoughts can dominate the feed and make scroll rhythm uneven.

## Proposed Solution

Add a shared collapsible preview behavior to the main thought text rendered by `editable_thought_content`.

Default behavior:

1. show up to about 15 lines of the main thought text
2. detect whether the rendered content actually overflows that limit
3. show a `Read more` button only when overflow exists
4. expand inline to show the full main thought text
5. allow collapsing back to the preview with `Show less`

This behavior should apply to:

- the signed-in idea home feed
- the main stream feed
- typed stream variants that reuse the same stream thought partial

It should not change:

- comment preview rendering
- section preview rendering
- public homepage cards or marketing sections

## UX Details

### Collapsed state

- Main thought text is visually limited to roughly 15 lines.
- Short thoughts render normally with no expand control.
- The collapsed state may use a subtle fade or other soft visual cue at the bottom so the preview feels intentional rather than abruptly cut off.

The preferred implementation is:

- keep the full main thought text in the DOM
- visually clamp it with CSS in the read view
- determine overflow by comparing rendered collapsed height with full content height after mount

This keeps the content accessible while still giving a consistent preview treatment.

### Expanded state

- Clicking `Read more` expands only that card.
- The button label changes to `Show less`.
- Expanded state remains only for the current page view and is reset by reloads or later list refetches.

### Scope of expansion

- Only the main thought text expands and collapses.
- Comments and section previews remain exactly as they work today.

### Navigation and click behavior

- The main thought text should continue to support the current detail-page link behavior.
- The expand/collapse control should be a separate button placed directly under the main thought text.
- The expand button must prevent accidental navigation when clicked.
- The toggle button must not be nested inside an anchor or any other interactive control.
- Preferred DOM shape: the read-only text remains inside its existing detail link wrapper, and the expand/collapse button renders as a sibling element directly beneath that linked text block.

## Component Design

### `resources/views/idea/partials/editable_thought_content.blade.php`

This partial should own the preview behavior because it already centralizes main thought rendering for both list surfaces.

Recommended responsibilities:

- accept an optional preview configuration for list contexts
- initialize lightweight Alpine state for:
  - whether the content is expanded
  - whether the content overflows the collapsed limit
- render the text container with collapsed styling only when:
  - the card is not expanded
  - the card is not in edit mode
- render the toggle button only when the content overflows
- re-run overflow detection when layout changes can affect wrapping, such as initial mount and window resize

If the implementation needs a more targeted resize signal, a `ResizeObserver` on the text container is acceptable, but the design goal is simple and reliable re-measurement rather than a heavy abstraction.

The detail page should remain unaffected unless the preview mode is explicitly enabled by the caller.

### `resources/views/idea/index_thought_cards.blade.php`

Continue to use the shared content partial, but opt in to preview mode for the signed-in home feed so the card body gets the 15-line collapsed behavior.

### `resources/views/idea/stream_thoughts.blade.php`

Also opt in to the same preview mode so stream cards match the signed-in home feed.

This keeps the interaction model aligned across list surfaces without introducing two separate implementations.

Typed stream variants are included automatically anywhere the app reuses `resources/views/idea/stream_thoughts.blade.php` and the shared content partial.

## State and Data Flow

The feature should be client-side only.

No database, controller, or API changes are required if the current rendered content is sufficient for overflow detection.

Expected flow:

1. Blade renders the full main thought content as it does today
2. Alpine initializes the card-level preview state
3. the rendered text block is measured after mount
4. if the text exceeds the collapsed limit, the toggle button is shown
5. expand/collapse updates only client-side state for that card

Because the feeds already support AJAX refresh and infinite loading, newly inserted cards should initialize with the default collapsed state.

Because wrapping can change with viewport width and font/layout timing, overflow detection should be allowed to re-measure after layout settles and when the viewport changes size.

## Accessibility

- Use a real `<button>` for the expand/collapse control
- Expose `aria-expanded`
- Associate the button with the controlled text region via a stable identifier and `aria-controls`
- Keep keyboard access intact for both the detail link and the toggle button
- Do not rely on hover-only cues to communicate that more content exists
- Keep the full collapsed text in the accessibility tree rather than replacing it with a shorter server-side excerpt
- Respect reduced-motion preferences if any animation or transition is added to the expand/collapse treatment

## Editing Behavior

Inline edit mode should bypass the collapsed preview styling.

When a card enters edit mode:

- show the full editable textarea as usual
- hide or disable the expand/collapse preview treatment for the read view
- avoid any clamp or fade overlay interfering with text selection or editing controls

## Edge Cases

- Very short thoughts should not render extra controls.
- Long unbroken strings should continue to wrap safely using the existing overflow protections.
- Multi-line content with preserved newlines should remain readable in both collapsed and expanded states.
- Expanded cards should revert to collapsed after full reload or list refetch.
- Realtime or pagination-inserted cards should initialize correctly without inheriting stale state from earlier cards.

## Testing

Prefer focused feature coverage around the rendered markup and hooks, plus a manual browser check for the client-side interaction.

Add or update tests to cover:

1. long thoughts on the signed-in home render the preview/toggle affordance
2. long thoughts on the stream render the preview/toggle affordance
3. short thoughts do not render the toggle affordance
4. the main thought text still links to the detail page
5. comments remain unaffected by the main thought preview behavior
6. preview markup retains safe wrapping behavior for long strings
7. inline edit markup is not broken by the preview feature
8. toggle markup exposes expected accessibility attributes such as `aria-expanded` and `aria-controls`
9. no invalid interactive nesting is introduced around the detail link and toggle button

Manual verification should confirm:

- collapsed height feels close to 15 lines
- `Read more` expands inline
- `Show less` reclamps the content
- clicking the toggle does not navigate away
- keyboard activation works for the toggle button
- reload resets cards back to collapsed
- newly inserted cards from list refresh or pagination initialize in the collapsed state and still measure overflow correctly

## Risks

- Overflow detection can be slightly brittle if it depends on runtime measurement and layout timing.
- Clamp styling could conflict with inline edit mode if not scoped carefully.
- Duplicating preview logic separately in stream and index would drift over time.

## Mitigations

- Keep the behavior centered in `editable_thought_content`
- Use one lightweight state model for both feeds
- Only show the control after confirming overflow
- Disable clamp styling while editing

## Recommendation

Implement the preview in the shared content partial and opt into it from the signed-in home and stream card partials.

This gives the user consistent feed scanning, keeps long thoughts readable inline, and preserves the current list-card architecture with minimal behavioral drift.

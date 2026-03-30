# Email Skipped Reason Info — Design Spec

**Date:** 2026-03-24
**Status:** Approved

## Overview

Improve the email newsletter research status UI so a `Research skipped` badge explains why the run was skipped. The explanation should be visible on both the email listing surfaces and the email detail page.

## Problem

Today, the app can show `Research skipped` on an email thought, but the user has to infer what happened from implementation behavior or retry the process manually. The stored skip reason already exists in metadata, but the UI does not surface it.

This creates two usability issues:

1. users cannot easily tell whether the skip was expected, such as not enough meaningful content
2. the `Research skipped` label can look like a generic failure rather than an intentional outcome

## Goals

- Surface the stored newsletter research skip reason alongside `Research skipped`
- Show the explanation in both the email listing views and the email detail page
- Support both hover and click/tap interactions for the info affordance
- Keep the list UI lightweight and consistent with existing Blade + Tailwind patterns

## Non-Goals

- Changing newsletter research processing logic
- Rewording all other newsletter research statuses
- Adding a JavaScript-heavy tooltip library
- Auto-retrying skipped research when sender rules change

## Current State

The listing and stream views include `resources/views/idea/partials/email_newsletter_research_status.blade.php`, which currently renders only the status badge and optional research link.

The detail page does not currently render this newsletter status partial at all. It shows sender rule state and email actions in `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`, but it does not show newsletter research status or the current skip reason.

The stored skip reason already exists in `thought.source_metadata.newsletter_research.reason` when the job marks a run as skipped.

## Proposed Solution

Extend the email newsletter status partial so it can render:

1. the existing `Research skipped` badge
2. a small info icon beside the badge
3. a hover tooltip and click/tap popover showing the stored skip reason
4. a short muted inline explanation beneath the status row when a skip reason exists

The same partial should be reused on:

- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- the email detail page header or sidebar

## UX Details

### Status badge row

When `newsletter_research.status === 'research_skipped'` and a trimmed non-empty `reason` exists:

- keep the `Research skipped` badge
- add a small circular info icon directly beside it
- use one shared explanation string for both the icon and the inline text

When there is no stored reason, or the stored reason is blank/whitespace after trimming:

- keep the existing `Research skipped` badge
- do not invent fallback copy beyond the current label

### Interactions

The info icon should support:

- hover/focus: show a compact tooltip
- click/tap: toggle the same content in a small popover for touch devices

The implementation should use lightweight Alpine state already available in Blade views rather than introducing a new dependency.

### Inline explanation

When a skip reason exists, render a muted helper line near the status:

- on list cards: directly under the status row, short and compact
- on detail page: in the email metadata/sidebar area, using the same wording

Example copy:

- `Skipped: not enough meaningful content to research.`

The inline text should prefer the exact stored reason, with only a small presentation wrapper like `Skipped:` added by the view.

## Component Design

### `resources/views/idea/partials/email_newsletter_research_status.blade.php`

Refactor this partial to:

- extract `newsletter_research.reason`
- identify when the state is specifically `research_skipped`
- render a status container instead of a badge-only fragment
- include a lightweight info icon button with tooltip/popover content
- optionally render the inline helper text below the badge row
- preserve the existing `data-email-research-status` hook on the rendered status badge for test stability

This partial should remain safe to include in both list and stream contexts without requiring extra controller changes.

### Detail page reuse

The detail page should also render the same partial rather than duplicating skip-reason logic in a separate sidebar-only snippet.

Preferred placement:

- add the partial to the email detail metadata area so the explanation appears near the existing email actions and sender-rule context

This is intentionally a broader detail-page enhancement than list-only copy. Adding the partial to the detail page also introduces the current newsletter research status row there for non-skipped states, which is acceptable and desirable for consistency.

If needed, the partial may accept a simple context flag such as `compact` or `showInlineReason`, but the default behavior should work for current list usage.

## Data Flow

Existing behavior already writes:

- `newsletter_research.status = research_skipped`
- `newsletter_research.reason = <skip reason>`

No backend schema or job changes are required for this feature. The work is presentation-only unless a missing detail is discovered during testing.

## Accessibility

- the info button must be keyboard focusable
- tooltip/popover content must be readable without hover-only interaction
- `aria-label` should describe the button, e.g. `Why research was skipped`
- the trigger should expose `aria-expanded` and `aria-controls`
- the popover panel should have a stable unique `id` per rendered thought instance
- click-outside and `Escape` should dismiss the popover
- focus should not be trapped

## Testing

Add feature coverage for the rendered HTML:

1. listing pages show the skip reason text when `research_skipped` includes a reason
2. listing pages render the info trigger only for skipped statuses with a reason
3. detail page shows the same skip reason for an email thought
4. non-skipped statuses do not render the skip-reason info UI
5. skipped status without a reason continues to render only the badge
6. HTML in the stored reason is escaped in rendered output
7. the status badge keeps the `data-email-research-status` attribute
8. the info trigger renders expected accessibility attributes such as `aria-label`

Prefer feature tests around rendered output instead of JavaScript interaction tests.

## Risks

- Inline reason text could make dense list cards feel noisier if the stored reason is long
- Tooltip/popover markup could become brittle if duplicated instead of centralized in the existing partial

## Mitigations

- keep the inline explanation muted and small
- keep list-context reason text constrained with wrapping and/or a modest max width
- reuse the existing email newsletter status partial for all surfaces
- scope the expanded UI to `research_skipped` only

## Out of Scope

- Translating or rewriting stored skip reasons from backend jobs
- Showing detailed explanations for `research_failed`, `research_partial`, or `research_completed`
- Adding analytics for tooltip usage

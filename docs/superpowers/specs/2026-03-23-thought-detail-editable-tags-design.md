# Thought Detail Editable Tags Design

**Date:** 2026-03-23
**Status:** Approved

## Overview

The app already supports inline tag editing on thought cards in list-style views through the shared `thought_tag_row` partial and the existing `PATCH /ideas/{thought}/tags` endpoint.

The thought detail page currently renders tags in read-only mode and hides the tag row entirely when a thought has no tags. This design makes tags editable on the thought detail page and keeps the tag row visible even for thoughts with an empty tag list, so the user can add the first tag directly from the detail page.

## Goals

- Allow tag editing on the thought detail page
- Reuse the existing inline tag editing UI and backend update flow
- Let the user add the first tag from the detail page when a thought currently has no tags
- Keep tag behavior consistent between detail and list views

## Non-Goals

- Creating a separate detail-page-only tag editor
- Changing tag normalization, authorization, or persistence rules
- Adding tag autocomplete or suggestions
- Changing tag behavior on non-owner views

## Existing Infrastructure To Reuse

The implementation should reuse the current tag editing stack instead of introducing a parallel flow:

- `resources/views/idea/partials/thought_tag_row.blade.php`
- `resources/js/app.js` via `Alpine.data('thoughtTagRow', ...)`
- `App\Http\Controllers\IdeaController::updateTags()`
- `routes/web.php` route `ideas.update-tags`
- existing feature coverage in `tests/Feature/UpdateThoughtTagsTest.php`

## UX Design

### Detail page tag row

The thought detail header should always render the shared tag row partial, regardless of whether the thought currently has tags.

Behavior:

- when tags exist, show the current tag pills and the existing inline edit affordance
- when tags are empty, still show the row so the user can start editing and add the first tag
- use the same existing inline controls already available elsewhere: edit, add tag, remove tag, and done

This keeps the detail page aligned with the rest of the app rather than introducing a second editing pattern.

When there are no tags, the detail page should still use the same first-step interaction as list views: the row renders with the existing `Edit` control, and the add input appears only after entering edit mode.

### Empty state

The empty-tag state should not collapse the entire tag row. The row should remain present so the user has an obvious place to click to start adding tags.

This design prefers consistency and discoverability over a cleaner but non-actionable empty state.

## Implementation Design

### Blade rendering

Update `resources/views/idea/partials/thought_detail_header.blade.php` so it:

- always includes `idea.partials.thought_tag_row`
- passes `editable => true` instead of `editable => false`
- no longer gates rendering on `tags !== []`
- preserves the existing vertical spacing for the tag block so the header layout remains visually consistent when the row is always present

The shared partial should remain the single rendering path for tags on the detail page.

### Backend behavior

No backend changes are required for v1 of this feature.

The detail page should continue using the existing `ideas.update-tags` endpoint, which already:

- authorizes updates against the thought owner
- validates that `tags` is an array
- normalizes and deduplicates tags
- preserves unrelated metadata keys
- returns JSON for the existing inline editing flow

### Ownership and permissions

The thought detail route is already owner-only. Because of that, rendering the shared tag row with `editable => true` is acceptable for this page and does not introduce a new cross-user editing surface.

If the detail page authorization model changes in the future, editability should be revisited and tied explicitly to ownership.

## Error Handling

The detail page should inherit the current inline tag editing behavior:

- successful add/remove operations update the visible tag list in place
- failed requests show the existing inline error message from `thoughtTagRow`
- invalid or unauthorized updates continue to rely on the current backend responses

No detail-page-specific error handling is needed beyond what the shared tag editor already does.

## Testing

Add feature coverage for the detail page tag-editing affordance.

Required cases:

- thought detail page shows the editable tag controls when tags already exist
- thought detail page still renders the tag row when the thought has no tags
- a thought with no tags can receive its first tag through the existing update endpoint, preferably covered by a detail-page-focused regression test rather than duplicating endpoint-only coverage that already exists elsewhere
- non-owners still cannot update tags through the existing endpoint

At minimum, tests should guard against regressions where the detail header switches back to read-only mode or hides the tag row for empty tag lists.

## Out Of Scope

- redesigning the detail page header layout
- adding autosuggestions, keyboard shortcuts, or bulk tag editing
- changing stream or list-page tag behavior
- introducing a separate dedicated tag management page

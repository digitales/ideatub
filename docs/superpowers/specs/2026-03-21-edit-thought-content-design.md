# IdeaTub — Edit thought content (inline, preserve tags)

**Date:** 2026-03-21  
**Status:** Draft  
**Scope:** Allow users to edit the content of any thought they own inline on the card, with explicit Save / Cancel controls, without changing tags as part of the edit flow.

## Overview

- **Goal:** Let users quickly correct typos or small wording mistakes in saved thoughts.
- **UX:** Inline edit on the card itself, available anywhere the owner sees a thought card. Edit mode shows a textarea plus **Save** and **Cancel**.
- **Tag safety:** Editing content must not alter `metadata.tags` or route through the tag-edit path.
- **Backend:** Single PATCH endpoint for thought content only.

## 1. Backend

### 1.1 Route and controller

- **Route:** `PATCH /ideas/{thought}/content` (name: `ideas.update-content`). Use existing `{thought}` route model binding; place alongside `ideas.update-tags`, `ideas.toggle-completed`, `ideas.destroy`, and `ideas.research` in `routes/web.php`.
- **Controller:** New method on `IdeaController`, e.g. `updateContent(Request $request, Thought $thought)`. Authorize with `$this->authorize('update', $thought)`.

### 1.2 Request and validation

- **Body:** JSON or form with `content` string. Example: `{ "content": "Fixed typo in this thought." }`.
- **Validation:** `content` required, string, max `65535`. Whitespace-only input (e.g. `"   "`) is treated as invalid after trimming.
- **Normalization:** Reuse the model's existing content mutator/accessor behavior so HTML entities are decoded the same way as on create. Do not add tag normalization or metadata merging to this endpoint.

### 1.3 Response and behavior

- Update only the `content` column, e.g. `$thought->update(['content' => $validated['content']]);`
- Do **not** rewrite `metadata`, `metadata.tags`, or `source_metadata`.
- **Response:** For AJAX / JSON requests (consistent with sibling endpoints such as delete: `expectsJson() || ajax()`), return `200` with `{ "content": "...saved content..." }`. For non-AJAX requests, redirect back with success flash.
- **Side effects:** Existing `Thought::updated` behavior remains in place (e.g. Evernote sync if configured). v1 does not introduce re-embedding on edit, so semantic-search relevance may remain temporarily stale after a content correction.

## 2. Card UX

### 2.1 Where editing appears

- Content editing is available anywhere the owner sees a thought card:
  - `resources/views/idea/index_thought_cards.blade.php`
  - `resources/views/idea/stream_thoughts.blade.php`
  - `resources/views/idea/partials/ideas_list.blade.php`
- Scope includes any owned thought content shown as a standalone editable card row, including top-level thoughts and ideas.
- Research thoughts are in scope only when rendered as standalone thought cards with card actions. Inline research preview snippets inside the Ideas page research block are out of scope for v1.
- Replies/comments are editable in v1 only when the reply itself is rendered as its own thought card with card actions. Nested comment snippets rendered inside a parent card's comments list are out of scope for v1.
- Non-owners do not see edit controls.

### 2.2 Entry point

- Reuse the existing card actions pattern rather than adding a second independent control.
- Add **Edit** to `resources/views/idea/partials/thought_card_actions.blade.php`, above **Delete**.
- Choosing **Edit** closes the action menu and switches only that card into content edit mode.
- The Ideas list does not currently render `thought_card_actions`, so bringing it into scope requires adding the same actions-menu partial to each idea row and fitting it into that layout explicitly rather than assuming the menu already exists.

### 2.3 Inline editor

- In edit mode, replace the rendered thought text with:
  - a textarea prefilled with the current full thought content
  - a **Save** button
  - a **Cancel** button
  - a small inline error area
- **Save:** sends the content-only PATCH request.
- **Cancel:** exits edit mode and restores the original card view without persisting changes.
- **Keyboard:** `Escape` cancels. `Enter` should continue to insert newlines; save remains explicit via the Save button.

### 2.4 Tags remain untouched

- The tag row remains rendered as its own UI and is not converted into editable content state when editing text.
- The content editor must not share request payloads, local state, or save handlers with `thoughtTagRow`.
- The content update request sends only `content`, so tags remain unchanged even if the card has editable tags visible nearby.
- If tag-edit mode is open when content editing begins, the implementation may close tag-edit mode for clarity, but it must not rewrite tags as part of that transition.

### 2.5 Truncated views

- The Ideas page currently truncates displayed content in list view. Entering edit mode must still load the full stored thought content, not the truncated preview text.
- After save or cancel, the card may return to its existing display format for that view (including truncation where the view already truncates content).

## 3. Frontend implementation shape

### 3.1 Shared behavior

- Implement a small shared Alpine component in `resources/js/app.js`, similar in style to `thoughtTagRow` and `thoughtCardActions`.
- Preferred boundary: put a single content-edit Alpine component on the card root so both the menu entry point and the editable body can coordinate through one local state container. In Home and Stream, that root already carries `data-thought-id`; in the Ideas list, the implementation must add equivalent root wiring explicitly (`data-thought-id`, edit state container, update URL, and access to the full untruncated content). `thoughtCardActions` can then call into that root component (directly or via Alpine events) instead of inventing cross-component global state.
- Suggested state:
  - `editing`
  - `draftContent`
  - `originalContent`
  - `saving`
  - `error`
  - `updateUrl`
- Suggested methods:
  - `startEdit()`
  - `cancelEdit()`
  - `saveEdit()`

### 3.2 Rendering strategy

- Prefer a shared partial for the editable thought body, or a small consistent inline block in each card template, so content display/edit behavior stays aligned across Home, Stream, and Ideas.
- Keep this separate from tag partials and separate from delete confirmation state, even if the actions menu is the entry point.
- The component should preserve line breaks and continue using the decoded content value that views already render today.

### 3.3 Request behavior

- Send `PATCH` with JSON:
  - `content`
- Include CSRF token and `Accept: application/json`.
- On success:
  - update the local displayed content
  - exit edit mode
  - clear errors
- On validation failure (`422`):
  - keep edit mode open
  - show the inline validation message
- On auth/session failure (`401` / `403` / `419`):
  - keep edit mode open
  - show a short message such as "Please sign in again."
- On other failures:
  - keep edit mode open
  - show a generic inline error

## 4. Edge cases and consistency

### 4.1 Concurrent changes

- If the thought is deleted before save, the server returns `404`. Frontend should show a simple failure message and avoid pretending the save worked.
- If another tab edited the same thought first, v1 can use last-write-wins behavior; no optimistic locking is required.

### 4.2 Empty or unchanged edits

- Empty content is rejected by validation.
- Unchanged content may either:
  - keep Save enabled and round-trip harmlessly, or
  - disable Save until content changes.
- Either choice is acceptable for v1, but the implementation plan should pick one explicitly. Slight preference: disable Save until changed.

### 4.3 Permissions

- Owners can edit their own thoughts using the existing `ThoughtPolicy::update`.
- Guests and non-owners receive standard unauthorized responses and do not see edit controls in the UI.

### 4.4 Tags and metadata safety

- Existing tags must remain logically unchanged after a successful content edit.
- Other metadata keys such as `type`, `completed`, `logged_date`, `idea_id`, and `research_pending` must also remain unchanged.
- Tests should compare decoded/reloaded metadata values rather than serialized JSON bytes.

## 5. Testing

### 5.1 Backend tests

- Authorized owner can update thought content via JSON.
- Guest cannot update thought content.
- Another user cannot update someone else's thought content.
- Validation rejects empty, whitespace-only, or otherwise invalid content.
- Updating content preserves `metadata.tags`.
- Updating content preserves unrelated metadata keys.

### 5.2 UI / integration tests

- Edit action is shown only for the owner.
- Clicking **Edit** opens inline edit mode for that card only.
- **Cancel** restores the previous view without changing the database.
- **Save** updates the rendered content and exits edit mode.
- Validation error keeps the editor open with inline feedback.
- Ideas-page edit mode uses full content even when view mode is truncated.
- Nested comment snippets do not incorrectly show edit controls unless and until they are promoted to full editable cards.

## 6. Out of scope (v1)

- Rich text editing or markdown editing.
- Revision history / undo.
- Editing tags as part of the same save request.
- Bulk content edits.
- Conflict resolution UI for concurrent edits.

## 7. Implementation notes

- Keep content editing as a dedicated path, separate from `updateTags()`, so typo correction cannot accidentally rewrite tags.
- Reuse the existing action-menu and Alpine patterns already present in `resources/views/idea/partials/thought_card_actions.blade.php` and `resources/js/app.js`.
- Because `resources/views/idea/partials/ideas_list.blade.php` does not currently use `thought_card_actions`, the implementation plan must include the small layout change needed to add the shared actions menu there.
- Replies shown only inside nested comment lists are explicitly out of scope for this v1; follow-up work can decide whether to convert those snippets into editable cards or add a separate interaction pattern.
- Editing policy for externally sourced thoughts should remain "owner can edit any owned thought shown in these card views" for v1 unless product requirements later carve out exceptions by `source`.

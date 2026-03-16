# IdeaTub — Editable thought tags (inline, all views)

**Date:** 2026-03-16  
**Status:** Draft  
**Scope:** Allow users to edit tags on any thought (add, remove, including new tags) inline on the card, on Home, Ideas, and Stream.

## Overview

- **Goal:** Fix wrong auto-tags and add missing tags. Support both existing tags and new tags (free-form).
- **UX:** Option A — inline on the card: click a tag to remove, “+ Tag” / input to add. No modal. Same behaviour on Home, Ideas, and Stream.
- **Backend:** Single PATCH endpoint for thought tags; optional tag-suggestions endpoint for autocomplete later.

## 1. Backend

### 1.1 Route and controller

- **Route:** `PATCH /ideas/{thought}/tags` (name: `ideas.update-tags`). Uses existing `{thought}` route model binding; place alongside `ideas.toggle-completed` and `ideas.research`.
- **Controller:** New method on `IdeaController` (e.g. `updateTags(Request $request, Thought $thought)`). Authorize `update` on the thought via `$this->authorize('update', $thought)`.

### 1.2 Request and validation

- **Body:** JSON or form: `tags` = array of strings. Example: `{ "tags": ["plan:editable-tags", "stream"] }`.
- **Validation:** `tags` required, array; each element string, max 100 chars, trimmed. After validation, normalize with `Thought::normalizeMetadataTags(['tags' => $tags])` then deduplicate (e.g. `array_unique` on the normalized tags array) so tags are lowercase, trimmed, and unique.

### 1.3 Response and behaviour

- Merge into `metadata`: set `$thought->metadata['tags']` to the normalized list; leave other `metadata` keys (e.g. `type`, `completed`, `logged_date`, `idea_id`) unchanged.
- Save: `$thought->update(['metadata' => $metadata])`. Existing `Thought::updated` listener will dispatch Evernote sync if configured.
- **Response:** If request expects JSON (e.g. AJAX): return `200` with `{ "tags": [...] }` (updated tags array). Otherwise redirect back with success flash.

### 1.4 Optional: tag suggestions (later)

- Endpoint such as `GET /ideas/tag-suggestions?q=...` returning unique tag strings from the current user’s thoughts, filtered by query, for autocomplete. Can be added in a follow-up; not required for v1.

## 2. Shared tag row partial

### 2.1 Purpose

- One Blade partial used everywhere a thought card shows tags, so behaviour and styling are consistent and we only implement “remove / add” once.

### 2.2 Contract

- **File:** `resources/views/idea/partials/thought_tag_row.blade.php`.
- **Variables:** `$thought` (required), `$editable` (optional). When omitted, default to `true` for owner-only views; callers that may show cards to non-owners should pass `$editable = auth()->check() && auth()->id() === $thought->user_id` explicitly.
- **Renders:**
  - For each tag: pill with link to `route('idea.stream', ['tag' => Str::slug($tag, '_')])` and, when `$editable`, a remove control (e.g. “×” or “Remove”) that triggers PATCH with tags minus this one.
  - When `$editable`: “Add tag” control — either a small “+ Tag” that reveals an input, or an input visible by default (design choice in implementation). Input allows typing any string (new or existing); on submit (Enter or button), add tag and PATCH.
- **Styling:** Reuse existing tag pill styles from `stream_thoughts` / `index_thought_cards` (e.g. `tagMap` / `tagColors`) so the row looks the same as today when not interacting.

### 2.3 JavaScript behaviour

- **Remove:** Click remove on a tag → send `PATCH /ideas/{thought}/tags` with `tags` = current list without that tag. On success, update the card in the DOM (e.g. remove the chip or re-render the tag row). On failure, show a small error (e.g. toast or inline message).
- **Add:** User types in the add input; on Enter or “Add” / blur, append the normalized tag (client can trim/lowercase for immediate feedback; server re-normalizes). Send PATCH with new list. On success, add the chip and clear the input. Ignore empty or duplicate (server also dedupes).
- **New tags:** Any string is allowed; no restriction to existing tags. Optional: later add a suggestions dropdown powered by tag-suggestions endpoint.
- Implementation can use Alpine.js (if already in the project), vanilla JS, or a small shared script; avoid full-page reload for a smooth inline experience.

## 3. Where the partial is used

- **Stream:** In `resources/views/idea/stream_thoughts.blade.php`, replace the current tag loop and link block with `@include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])` (or equivalent). Preserve surrounding layout (timestamp, source, etc.).
- **Home (index):** In `resources/views/idea/index_thought_cards.blade.php`, same: replace the tag block with the partial so recent and search result cards get editable tags.
- **Ideas:** In `resources/views/idea/ideas.blade.php`, idea cards currently do not show tags. Add the tag row partial to each idea card (e.g. below the content / logged date, before or after the research block) so tags are visible and editable on the Ideas page as well.

## 4. Edge cases and consistency

- **Empty tags:** Allow `tags: []`. The thought then has no tags; the row shows only “Add tag” (when editable).
- **Permissions:** Only the thought owner sees remove/add controls; use `$editable` as above so non-owners (if ever shown) see read-only tags only.
- **Slug resolution:** New tags are stored in normalized form. Existing `resolveTagSlugToCanonical` will resolve them once at least one thought has that tag; no change needed.
- **Evernote:** No extra work; `Thought::updated` already dispatches sync.
- **Validation:** Reject non-array or invalid elements; return 422 with Laravel’s standard JSON validation format (`message` + `errors`) when the request expects JSON.

## 5. Out of scope (v1)

- Tag suggestions/autocomplete API (can be added later).
- Bulk edit tags across multiple thoughts.
- Tag “management” page (rename, merge, delete globally).

## 6. Implementation notes

- Reuse `Thought::normalizeMetadataTags()` for server-side normalization. When merging into `metadata`, preserve all other keys (e.g. for ideas: `type`, `completed`, `logged_date`).
- Ensure the PATCH route is registered in the same middleware group as other idea routes (auth).
- For accessibility: ensure remove and add controls have clear labels (e.g. “Remove tag X”, “Add tag”) and keyboard support where appropriate.
- **CSRF:** AJAX PATCH requests must include a CSRF token (e.g. `X-XSRF-TOKEN` header or `_token` in the request body); ensure the plan includes this.

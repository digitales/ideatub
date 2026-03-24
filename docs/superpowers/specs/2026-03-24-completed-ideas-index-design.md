# IdeaTub - Completed ideas index design

**Date:** 2026-03-24  
**Status:** Approved design direction  
**Scope:** Hide completed ideas from the active ideas surfaces and add a dedicated `Completed ideas` index ordered by most recent completion.

## Overview

- **Goal:** Keep the main `Ideas` area focused on active work while preserving access to finished ideas in a dedicated archive-style view.
- **Primary direction:** Remove completed ideas from both `Ideas` and `Ideas to revisit`, and add a sibling `Completed ideas` page for finished items.
- **Secondary goal:** Support reliable "most recently completed first" ordering by storing a completion timestamp when an idea is marked complete.

## 1. Product behavior

### 1.1 Active vs completed idea surfaces

The app should split idea browsing into three related surfaces:

- `Ideas` for incomplete ideas only
- `Ideas to revisit` for incomplete ideas selected by revisit logic
- `Completed ideas` for completed ideas only

This change is intentionally about information architecture, not a new idea model. Ideas remain thoughts with idea metadata; the difference is which ideas appear on which page.

### 1.2 Completion behavior

When a user marks an idea complete from any supported completion surface, the idea should:

- stop appearing on `Ideas`
- stop appearing on `Ideas to revisit`
- start appearing on `Completed ideas`

When a user reopens an idea from the idea detail page, it should return to the active `Ideas` list and disappear from `Completed ideas`.

### 1.3 Reopen rule

The `Completed ideas` page should not offer an inline completion toggle.

Reopening belongs on the idea detail page only, and the detail page should expose that reopen control for completed ideas. This keeps the completed index lightweight and archive-like instead of turning it into a second active-management surface while still giving users a clear path to restore an idea.

## 2. Data model

### 2.1 Metadata shape

Keep ideas in the existing `Thought` model with metadata:

- `metadata.type = 'idea'`
- `metadata.completed = boolean`
- `metadata.logged_date = YYYY-MM-DD` when present

Add one field for completed ideas:

- `metadata.completed_at = ISO-8601 timestamp`

### 2.2 Write rules

When an idea is marked complete:

- set `metadata.completed = true`
- set `metadata.completed_at = now()`

When an idea is reopened:

- set `metadata.completed = false`
- clear `metadata.completed_at`

The implementation should preserve unrelated metadata keys such as tags and any existing idea-specific metadata.

### 2.3 Legacy completed ideas

Older records may have `completed = true` but no `completed_at`.

Those ideas should still appear on `Completed ideas` rather than being hidden or treated as invalid. For ordering, items with a real `completed_at` should sort ahead of legacy completed items without one. Within the legacy group, use a stable fallback order of newest `updated_at` first, then `id` descending as a final tiebreaker.

## 3. Navigation and routing

### 3.1 Ideas-section navigation

The shared ideas navigation should expose three sibling destinations:

- `Ideas`
- `Ideas to revisit`
- `Completed ideas`

`Completed ideas` is an ideas sub-view, not a new top-level app area.

### 3.2 Route expectations

The implementation should preserve stable, bookmarkable routes for all three surfaces:

- existing active ideas route
- existing revisit route
- new completed ideas route

The exact route name can follow the current route naming pattern, but it should read as part of the `Ideas` section rather than as a generic archive feature.

## 4. Query behavior

### 4.1 `Ideas`

The `Ideas` page should query incomplete ideas only.

In practice, that means thoughts with `metadata.type = 'idea'` where `metadata.completed` is not `true`.

Implementations may normalize malformed metadata during reads if needed, but the required product behavior is:

- boolean `true` means completed
- missing, `false`, or malformed non-true values are treated as incomplete for index-filtering purposes

### 4.2 `Ideas to revisit`

`Ideas to revisit` keeps its current purpose and selection model, but it should continue to exclude completed ideas.

No new revisit behavior is required beyond ensuring the completed/completion-timestamp changes do not regress current filtering.

### 4.3 `Completed ideas`

The `Completed ideas` page should query completed ideas only:

- `metadata.type = 'idea'`
- `metadata.completed = true`

Ordering should be:

1. items with `metadata.completed_at`
2. sorted by `metadata.completed_at` descending
3. legacy completed items without `completed_at` after timestamped items

Pagination should follow the same pattern already used for the main `Ideas` list.

## 5. UI behavior

### 5.1 `Ideas`

The current completion control on `Ideas` can remain, but its effect changes from "show as completed in place" to "move out of this index."

After completion, the item should no longer remain visible on the active ideas page on refresh or redirected reload.

### 5.2 `Completed ideas`

Each row on `Completed ideas` should show:

- the idea snippet/content preview
- the original logged date when available
- the completed date, rendered in the app's normal local user-facing date style

The page should support navigation through to the idea detail page, where the reopen control should be available for completed ideas.

The page should not render an inline checkbox or "mark incomplete" action.

### 5.3 Empty state

If the user has no completed ideas, show a simple empty state such as:

`No completed ideas yet.`

## 6. Error handling and edge cases

### 6.1 Non-idea thoughts

Existing guards that prevent completion actions on non-idea thoughts should stay in place.

This design does not expand completion behavior to any non-idea thought types.

### 6.2 Metadata preservation

Updating completion status must not replace the entire metadata object with only completion fields.

The implementation should merge the completion fields into existing metadata so tags, logged date, and other supported fields survive the transition.

### 6.3 Missing timestamps

If `completed_at` is missing on a completed idea, the app should:

- still show the record on `Completed ideas`
- avoid crashing or rejecting the record
- place it after timestamped completed ideas

## 7. Testing expectations

### 7.1 Feature coverage

Add or update feature tests to verify:

- completing an idea removes it from `Ideas`
- completed ideas do not appear on `Ideas to revisit`
- completed ideas appear on `Completed ideas`
- `Completed ideas` orders by newest `completed_at` first
- legacy completed ideas without `completed_at` still appear on `Completed ideas` after timestamped completed items
- reopening from the detail page removes the idea from `Completed ideas` and returns it to `Ideas`

### 7.2 Lower-level coverage

Add unit or service-level coverage for any shared query helpers or scopes that distinguish:

- incomplete ideas
- completed ideas
- legacy completed ideas without `completed_at`

This is especially important if implementation introduces reusable scopes/helpers for active and completed idea queries.

## 8. Out of scope

This design does not include:

- inline reopening from `Completed ideas`
- manual ordering of completed ideas
- a separate archive table or new persistence model
- additional lifecycle states beyond complete vs incomplete

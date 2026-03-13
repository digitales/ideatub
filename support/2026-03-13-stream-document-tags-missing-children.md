# Document tags only showing parent, missing children sections — Customer Support Investigation

**Date**: 2026-03-13
**Status**: Resolved
**Customer**: User report (support)
**Priority**: High
**Reported By**: Customer

## Issue Description

When viewing Stream filtered by a document tag (e.g. `decision:my-doc` or a plan/spec slug), only the parent item was shown. Child sections (document chunks synced via MCP `capture_plan` with `parent_id`) were missing, so larger documents were missing most of their content.

## Customer Impact

- **Users affected**: Anyone syncing multi-section documents to IdeaTub and viewing them via Stream by tag
- **Severity**: High — document view was incomplete and unusable for long-form docs
- **Business impact**: Core “long-form doc in Stream” use case was broken

## Investigation Steps

1. Reviewed **Stream tag filter** in `IdeaController::stream()`: query used `topLevel()` and `whereJsonContains('metadata->tags', $canonicalTag)`, so only top-level thoughts that had the tag were returned.
2. Confirmed **sync behaviour**: `capture_plan` is called once per section; sections can have a root thought and child thoughts linked via `parent_id`. All sections (and optionally the root) receive the same tag (e.g. `decision:slug`).
3. Observed that when the **root thought has no tag** (only section thoughts do), the root never appeared in the tag filter because it wasn’t top-level with that tag.
4. Checked **stream view** `stream_thoughts.blade.php`: child thoughts are rendered as `comments`. Comment content was truncated with `Str::limit($comment->getDecodedContent(), 200)`, so even when children loaded, only 200 characters per section were shown.

## Root Cause Analysis

1. **Query**: Stream with a tag filter only included top-level thoughts that had the tag. It did not include top-level thoughts whose *children* had the tag. So document roots that had no tag (only section children tagged) never appeared.
2. **Display**: When viewing by tag (document view), section children were shown as comments but truncated to 200 characters, so most of the document content was missing.

## Resolution

### 1. Include parents when any child has the tag

**File:** `app/Http/Controllers/IdeaController.php`

When a tag filter is applied, the query now includes top-level thoughts that:
- have the tag themselves, **or**
- have at least one child (comment) with that tag.

Implementation: replaced a single `whereJsonContains('metadata->tags', $canonicalTag)` with a `where()` group that uses `orWhereHas('comments', ...)` so document roots appear even when only section thoughts are tagged.

### 2. Show full section content in tag view

**Files:**  
- `resources/views/idea/stream.blade.php`  
- `resources/views/idea/stream_thoughts.blade.php`  
- `app/Http/Controllers/IdeaController.php` (AJAX fragment)

When Stream is filtered by a tag (document view), child thoughts (sections) are now shown in full instead of being limited to 200 characters. A `showFullSections` flag is passed when `$tag` is set (full page and AJAX load-more), and the partial uses it to show full `$comment->getDecodedContent()` instead of `Str::limit(..., 200)`. Non-tag Stream view is unchanged (comments still truncated at 200 chars).

## Prevention & Follow-up

- [ ] Consider adding a short note in sync docs (e.g. `ideatub-sync-docs.mdc`) that Stream by tag shows the full document (root + all section children) and that section content is shown in full in tag view.

## Related Issues

- Sync instructions: `.cursor/rules/ideatub-sync-docs.mdc`, `CLAUDE.md` (split at section titles, same `plan_slug` for all sections, optional root + `parent_id` for sections).

## References

- `docs/superpowers/specs/2026-03-12-tag-and-stream-design.md` — Stream and tag filter behaviour
- `app/Models/Thought.php` — `comments()` relationship (child thoughts via `parent_id`), `scopeTopLevel()`

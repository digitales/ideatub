# Research Note Detail Enhancements

**Date:** 2026-05-11
**Status:** Approved
**Scope:** Research show page — title editing, body editing, tag editing, project display

## Summary

Enhance the research note detail view (`research_show.blade.php`) with:
1. A dedicated editable title field (stored in `metadata['title']`)
2. Read-only project association display
3. Inline tag editing (reusing existing `thought_tag_row` partial)
4. Per-section body editing (reusing existing `editable_thought_content` partial)
5. A backfill command to extract titles for existing research notes

## Data Model

### Title Storage

- Stored in `metadata['title']` on the root research thought (string, max 255 chars).
- Null/missing = untitled (displayed as "Untitled research" placeholder).
- No schema migration required — `metadata` is already a JSON column.

### Existing Structures (unchanged)

- Tags: `metadata['tags']` (array of lowercase strings).
- Projects: `project_thought` pivot table (belongsToMany relationship).
- Sections: child thoughts with `parent_id` pointing to the root.

## Research Show Page Layout

Updated layout inside the existing white card (top to bottom):

1. **Back link** — unchanged.
2. **"Research" label** — unchanged (uppercase badge).
3. **Title** — editable heading. Shows `metadata['title']` or "Untitled research" placeholder.
4. **Project pills** — read-only, only rendered if the thought has project associations. Each pill links to the project page.
5. **Tag row** — inline editable (add/remove tags).
6. **Timestamp** — `created_at->diffForHumans()`.
7. **Related email / video / newsletter cards** — unchanged.
8. **Body content** — root HTML + sections, each individually editable.
9. **Comments** — unchanged.

## Title Editor

### Partial

New file: `resources/views/idea/partials/thought_detail_title.blade.php`

### Behavior

- Displays title as `<h1>` styled `text-[22px] font-semibold text-deep-indigo`.
- If no title set, shows "Untitled research" in `text-slate-brand/50` placeholder style.
- Click on the title (or an adjacent "Edit" link) switches to a single-line `<input type="text" maxlength="255">`.
- Escape cancels edit, Enter or blur saves.
- Saves via PATCH to `ideas.update-title`.

### Alpine Component

`thoughtTitleEditor` with state: `title`, `editing`, `draft`, `saving`, `error`.

Mirrors `thoughtContentEditor` but simplified for a single input field.

### Endpoint

- **Route:** `PATCH /ideas/{thought}/title` → `IdeaController::updateTitle`
- **Route name:** `ideas.update-title`
- **Validation:** `title` — nullable string, max 255.
- **Logic:** Merge into `$thought->metadata['title']`, save.
- **Response:** JSON `{ "title": "..." }` on success.
- **Authorization:** Same `update` policy as `updateContent`.

## Body Editing

### Approach

Wrap each content block in the research view with the existing `editable_thought_content` partial using `detailMarkdownRead => true`.

### Root Thought

- Pass root thought to `editable_thought_content` with:
  - `detailMarkdownRead => true`
  - `contentHtml => $root_html`
  - `rawEditorContent => $root->content`
  - `editable => true` (gated by DemoMode check)
- Edit button appears top-right of root content.
- Saves via existing `PATCH /ideas/{thought}/content`.

### Section Thoughts

- Each section in the loop gets its own `editable_thought_content` include.
- Passes the section's thought model, `content_html`, and raw `content`.
- Each section saves independently to its own thought record via the same endpoint.

### Controller Changes

`showResearch` passes:
- `editable => ! app(DemoMode::class)->enabled()`
- Section objects include the thought model (already present in `$sectionsWithHtml`).

No new endpoints needed.

## Tag Editing

### Approach

Include existing `thought_tag_row` partial in the research show header:

```blade
@include('idea.partials.thought_tag_row', ['thought' => $root, 'editable' => $editable])
```

No new code — the partial handles inline add/remove and saves via `PATCH /ideas/{thought}/tags`.

## Project Display

### Approach

Include existing `thought_detail_projects_and_links` partial in read-only mode:

```blade
@include('idea.partials.thought_detail_projects_and_links', [
    'thought' => $root,
    'thoughtProjectsForDetail' => $thoughtProjectsForDetail,
    'editable' => false,
])
```

### Controller Changes

- Eager-load `$thought->projects` in `showResearch`.
- Pass `thoughtProjectsForDetail => $thought->projects` to the view.

Read-only: no × buttons, no "Add to project" form.

## Backfill Command

### Command

`php artisan research:backfill-titles`

### Options

- `--user=` — scope to a specific user ID (optional; without it, processes all users).
- `--dry-run` — preview extractions without saving.

### Extraction Logic

1. Regex match for first `^#{1,3}\s+(.+)$` in the thought's `content`.
2. If no heading found, fall back to first 80 characters of `strip_tags(CommonMark($content))`, trimmed at word boundary.
3. Store result in `metadata['title']`.

### Behavior

- Processes thoughts where `metadata->type = 'research'` and `metadata->title` is null.
- Chunks of 100 for memory efficiency.
- Idempotent — skips thoughts with existing title.
- Output: count of updated vs skipped.

## MCP Integration

### `capture_plan` with `doc_type = 'research'`

When a research thought is created as a root (no `parent_id`) and `section_title` is provided in the capture call, copy `section_title` into `metadata['title']` on that thought if `metadata['title']` is not already set.

No breaking change — existing behavior preserved, title population is additive.

### Page Title Tag

`research_show.blade.php` `@section('title')` updated to prefer `$root->metadata['title']` when present, falling back to the current `Str::limit(strip_tags($rootHtml), 50)` logic.

## Files Changed

| File | Change |
|------|--------|
| `resources/views/idea/research_show.blade.php` | Add title, project, tag partials; wrap body in editable_thought_content |
| `resources/views/idea/partials/thought_detail_title.blade.php` | New partial |
| `resources/js/app.js` | Add `thoughtTitleEditor` Alpine component |
| `app/Http/Controllers/IdeaController.php` | Add `updateTitle` method; update `showResearch` to pass projects + editable flag |
| `routes/web.php` | Add `PATCH /ideas/{thought}/title` route |
| `app/Console/Commands/BackfillResearchTitles.php` | New artisan command |
| MCP thought creation logic | Copy `section_title` → `metadata['title']` for research roots |

## Out of Scope

- Adding/removing project associations from the research view (managed elsewhere).
- Editing section order or adding/deleting sections.
- Rich text (WYSIWYG) editing — stays as plain markdown textarea.
- Microsite research pages (these have their own reader layout; no changes).

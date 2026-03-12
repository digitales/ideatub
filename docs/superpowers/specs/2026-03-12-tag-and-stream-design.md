# IdeaTub — Tag-based navigation and Stream page

**Date:** 2026-03-12  
**Status:** Approved  
**Scope:** IdeaTub thinking space — tag links on thoughts, tag-filtered view, and a dedicated Stream page for all thoughts.

## Overview

- **Tag-based navigation:** Make each tag on a thought a link. Clicking a tag shows thoughts that have that tag (related-by-topic). Homepage stays “recent + capture”; tag filter can be applied on the homepage (e.g. `/?tag=work`) or on the Stream page (e.g. `/stream?tag=work`).
- **Stream page:** A dedicated route (e.g. `/stream`) that shows all of the user’s thoughts in reverse chronological order, with pagination or “Load more”. Optionally supports the same `?tag=` filter.
- **Homepage:** Unchanged in layout and behaviour except that tags become clickable (linking to the chosen tag view).

---

## 1. Tag-based navigation

### 1.1 URL and behaviour

- **Option A — Filter on index:** `GET /?tag=work` shows the same idea index page with thoughts filtered to those whose `metadata->tags` contains the given tag. Search `?q=` and tag can be combined or mutually exclusive (design choice: either “tag OR search” or “tag + search”; recommend **tag only** on index for simplicity, and keep search as the main discovery on `/`).
- **Option B — Filter on Stream:** Tag links go to `/stream?tag=work`. Homepage stays “recent only”; “by tag” lives on Stream.
- **Recommendation:** Use **Stream for tag filtering** so that:
  - Homepage remains strictly “recent thoughts” (and search), with no extra query logic.
  - One place for “browse by tag” and “browse all” = Stream.

So: **tag links point to `/stream?tag={tag}`**. Tag value in the URL should be URL-safe (e.g. raw tag string encoded, or a slug). Use the same tag string as stored in `metadata->tags` (after normalising when displaying links so casing is consistent if needed).

### 1.2 Backend: resolving tag from request

- **Route:** Stream route accepts optional query param `tag` (e.g. `?tag=work`).
- **Validation:** `tag` must be a non-empty string, max length (e.g. 100 chars). No HTML.
- **Query:** For Stream with `tag` present: filter thoughts with `whereJsonContains('metadata->tags', $tag)`. Tag matching: use the exact string from the request; tags in DB are stored as extracted (e.g. from OpenRouter). Consider normalising to lowercase for the query if tags are stored lowercase, or store/display as-is and match case-sensitively for simplicity in v1.
- **Empty result:** When filtering by tag returns no thoughts, show an empty state: “No thoughts with this tag” and a link back to Stream (or Home).

### 1.3 Where tags are clickable

- **Homepage (`/`):** In the “Recent thoughts” list, each tag pill is an `<a href="{{ route('idea.stream', ['tag' => $tag]) }}">` (or equivalent). Same styling as now, with hover to show it’s a link.
- **Stream page (`/stream`):** Same: each thought’s tags are links to `/stream?tag={tag}`. Optional: when viewing “by tag”, show a chip like “Tag: work” with a clear link to remove filter (e.g. “All thoughts” or “Clear tag”).

---

## 2. Stream page

### 2.1 Route and controller

- **Route:** `GET /stream` (auth middleware). Name: `idea.stream`.
- **Controller:** Either a new `StreamController` or a method on `IdeaController` (e.g. `stream()`). Recommendation: method on `IdeaController` to keep idea/thought UX in one place.

### 2.2 Data and query

- **Scope:** Current user’s thoughts only (`user_id = auth()->id()`).
- **Order:** Reverse chronological (`orderByDesc('created_at')`).
- **Include:** Top-level thoughts; optionally include comments (nested) for consistency with homepage. Recommendation: same as homepage — top-level with `comments` loaded so replies are visible.
- **Filter by tag:** When `?tag=...` is present, apply `whereJsonContains('metadata->tags', $tag)`.
- **Pagination:** Use cursor-based or offset pagination. For “Load more” UX, Laravel’s `LengthAwarePaginator` with a reasonable per-page size (e.g. 20) is sufficient; “Load more” button or infinite scroll can be added later. Initial implementation: **simple pagination** (e.g. 20 per page) with “Next / Previous” or “Load more” button.

### 2.3 View and layout

- **Layout:** Reuse `layouts/idea` so nav and behaviour (e.g. keyboard shortcuts) are consistent.
- **Content:** No capture box on Stream (Stream is for browsing). Reuse the same thought card markup as on the homepage (or a shared partial) so appearance is consistent. Header: e.g. “Stream” or “All thoughts”, and when `tag` is set: “Tag: {tag}” with link to clear.
- **Navigation:** Nav should include a link to “Stream” (or “All thoughts”) so users can get there from the homepage. Home remains the default landing for authenticated users.

---

## 3. Homepage changes (minimal)

- **No change** to hero, capture box, or “Recent thoughts” list logic (still top-level, limit 20, no tag filter on homepage).
- **Only change:** In the thought list, replace the non-clickable tag `<span>` with an `<a>` to the Stream with that tag, e.g. `route('idea.stream', ['tag' => $tag])`. Preserve existing tag styling (pill, colors). Ensure the link is accessible (e.g. focus state, no removal of tag text).

---

## 4. Edge cases and empty states

- **Tag with zero thoughts:** On `/stream?tag=xyz`, if no thoughts have that tag, show: “No thoughts with tag ‘xyz’.” and a link to “All thoughts” (Stream without tag).
- **Invalid or missing tag:** If `tag` is empty after trim or invalid, treat as “no tag filter” (show full stream).
- **Stream with no thoughts at all:** “No thoughts yet. Capture one from the home page.” with link to `/`.
- **Reply (parent_id):** When showing thoughts on Stream, show top-level thoughts; comments can be loaded as on the homepage. No need to show every comment as a separate row in the stream; keep the nested comment design.

---

## 5. Implementation notes

- **Tag query:** Use Laravel’s `whereJsonContains('metadata->tags', $tag)`. Works with PostgreSQL; SQLite JSON support may differ — verify on both if the project supports both.
- **Shared thought card:** Consider a Blade partial (e.g. `idea/partials/thought-card.blade.php`) for the single-thought block used on index and stream, to avoid duplication.
- **Route name:** Use `idea.stream` so tag links and nav are easy to generate (e.g. `route('idea.stream')`, `route('idea.stream', ['tag' => $tag])`).

---

## 6. Out of scope (for later)

- Semantic “related thoughts” (e.g. “More like this” via embeddings).
- Tag autocomplete or tag management page.
- Multiple tag filter (AND/OR).
- Infinite scroll (can be added later on Stream; start with pagination or “Load more”).

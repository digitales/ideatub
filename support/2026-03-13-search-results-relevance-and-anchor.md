# Search results back-fill and results anchor - Customer Support Investigation

**Date**: 2026-03-13
**Status**: Resolved
**Customer**: User report (support)
**Priority**: Medium
**Reported By**: Customer

## Issue Description

1. **Relevance**: When searching, the top results were relevant but the rest of the list appeared to be "back filled" to reach 20 thoughts. Only relevant thoughts should be shown for search.
2. **Scroll position**: When performing a search, the page should anchor to the "Results for \"XXX\"" section so it appears just below the fixed navigation bar, rather than leaving the user at the top of the page.

## Customer Impact

- **Users affected**: All users using semantic search on the idea index
- **Severity**: Medium — search felt noisy (low-relevance results) and required manual scrolling to see results
- **Business impact**: Reduced confidence in search and poorer UX

## Investigation Steps

1. Reviewed `IdeaController::index()` — search used `nearestTo($embedding, self::SEARCH_LIMIT)` with `SEARCH_LIMIT = 20`, always returning up to 20 thoughts by cosine distance with no minimum relevance.
2. Checked `Thought` model — `scopeNearestTo` uses pgvector `nearestNeighbors` and `take($limit)`; no similarity threshold.
3. Reviewed idea index view — "Results for \"…\"" heading had no `id` and no scroll-into-view on load; no `scroll-margin` for sticky nav.

## Root Cause Analysis

- **Back-fill**: The app requested the 20 nearest thoughts by embedding distance. There is no minimum similarity threshold, so the 15th–20th results could have low semantic relevance (high cosine distance) and still be included.
- **Anchor**: The results heading was not a scroll target, and the page did not scroll to results on load when `?q=` was present. The fixed nav also meant that scrolling to the top would hide the results heading under the nav without `scroll-margin-top`.

## Resolution

### 1. Only show relevant search results

- **`Thought` model**: Added `scopeNearestWithin(Builder $query, $embedding, float $maxDistance)` that filters by `whereRaw('embedding <=> ?::vector <= ?', ...)` and orders by distance (caller applies `paginate()` or `take()`).
- **`IdeaController`**: Replaced `nearestTo(..., limit)->get()` with `nearestWithin($embedding, self::SEARCH_MAX_DISTANCE)->paginate(self::SEARCH_LIMIT)` for search.
- **Constant**: `SEARCH_MAX_DISTANCE = 0.5` (cosine distance). Only thoughts with distance ≤ 0.5 are returned; the list can be shorter than 20 when there are fewer relevant matches.

### 2. Anchor results below nav and scroll on search

- **`resources/views/idea/index.blade.php`**:
  - Gave the results heading a stable id: `id="search-results"` and `role="region"` with `aria-label` for accessibility.
  - Added `scroll-mt-[5rem]` so when the element is scrolled into view, it sits just below the sticky nav (~5rem).
  - When `$query` is set, pushed a small script that on `DOMContentLoaded` runs `document.getElementById('search-results').scrollIntoView({ behavior: 'auto', block: 'start' })` so the page lands with "Results for \"XXX\"" visible under the nav.

## Follow-up: Pagination and infinite scroll (2026-03-13)

When there are more than 20 relevant results, search now supports:

- **Pagination**: Search uses `paginate(SEARCH_LIMIT)`; the first page shows up to 20 results.
- **Infinite scroll**: When there are more pages, a sentinel at the bottom of the list triggers a load of the next page via AJAX. The request includes `q`, `page`, and `replyable_offset` (count of replyable cards already shown) so the server can render correct Alpine indices for j/k navigation.
- **Partial**: `idea/index_thought_cards.blade.php` renders thought cards for both the initial page and the AJAX “load more” response.

Users can also rely on infinite scroll; the sentinel is only shown for search when `hasMorePages()` is true.

## Prevention & Follow-up

- [ ] Consider making `SEARCH_MAX_DISTANCE` configurable (e.g. via config or env) if users report too few or too many results.
- [ ] If needed, add a "No results" message when zero thoughts pass the threshold and suggest broadening the query.

## Related Issues

- Idea index search: `IdeaController::index()`, `Thought::scopeNearestWithin`, view `idea/index.blade.php`.

## References

- `app/Http/Controllers/IdeaController.php` — search branch and constants
- `app/Models/Thought.php` — `scopeNearestWithin`
- `resources/views/idea/index.blade.php` — `#search-results`, scroll script, `scroll-mt-[5rem]`
- pgvector cosine distance: `<=>` operator; only results within threshold are returned

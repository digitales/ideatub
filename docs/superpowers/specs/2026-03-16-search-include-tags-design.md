# IdeaTub — Search include tags (keyword match)

**Date:** 2026-03-16  
**Status:** Draft  
**Scope:** Extend search so that when the query matches (or partially matches) a thought’s tag, those thoughts appear in results — hybrid of tag match and semantic search, with tag matches first.

## Overview

- **Goal:** Typing a tag (or part of it) in search surfaces those thoughts at the top, then semantic matches. Same behaviour on web, REST API, and MCP so MCP is influenced by tags.
- **Approach:** Hybrid union — run tag-match query and semantic query; merge with tag matches first, dedupe by thought id; paginate over the combined list.

## 1. Backend behaviour

**Scope:** All search entry points use the same logic: `IdeaController::index` (web), `ThoughtsApiController::search` (REST), `McpController::searchThoughts` (MCP).

**Tag match rule:** Normalize the search query (trim, lowercase). A thought matches if any of its `metadata->tags` (array) either:
- **Exact:** equals the normalized query, or
- **Contains:** contains the normalized query as a substring (e.g. query `project-spec` matches tag `decision:project-spec`).

Use the same tag normalization as elsewhere (lowercase); only consider thoughts for the current user. Apply the same filters as the current search context (e.g. web: top-level, exclude research; API/MCP: same as today).

**Flow:**

1. **Tag matches:** Query thoughts where `metadata->tags` has at least one tag that equals the normalized query or contains it. Order by `created_at` desc. Apply same scopes as current search (e.g. `topLevel()`, `excludingResearch()` for web).
2. **Semantic matches:** Unchanged: embed query, run `nearestWithin` (with fallback to `nearestTo` when empty), same limit/threshold per context.
3. **Merge:** Remove from the semantic list any thought id that appears in the tag-match list. Final order: tag matches first (by `created_at` desc), then semantic matches (by distance).
4. **Pagination:** Single combined page size (e.g. 20). Page 1 = first N of merged list; page 2 = next N, etc. Tag matches can dominate page 1 when the query is tag-like.

**Edge cases:**

- Empty query: no search; keep current “recent thoughts” behaviour (no tag query).
- Query matches only tags (no semantic results): result is just the tag-matched list.
- Query matches no tags: result is just the semantic list (no change to current behaviour).

**MCP:** `search_thoughts` uses this same hybrid behaviour so that MCP clients get tag-influenced results (tag matches first, then semantic) in the returned `thoughts` array.

## 2. API response and frontend

- **Response shape:** Keep a single `thoughts` array in merged order. No `match_kind` in v1; can be added later if the UI needs “Tag matches” vs “Semantic matches” sections.
- **Web (idea index):** Single list; tag matches naturally appear first. No separate “Tag matches” / “Semantic matches” headings for v1.
- **REST and MCP:** Same merged list; no change to response schema.

## 3. Implementation notes

### 3.1 Tag-match query (DB)

- **Exact match:** Use `whereJsonContains('metadata->tags', $normalizedQuery)` where supported (Laravel; PostgreSQL and SQLite differ — follow existing Stream/tag patterns).
- **Contains match:** “Any tag contains normalized query” requires driver-specific SQL:
  - **PostgreSQL:** e.g. `EXISTS (SELECT 1 FROM jsonb_array_elements_text(metadata->'tags') AS t WHERE t LIKE '%' || ? || '%')` (bind normalized query; escape `%`/`_` for LIKE).
  - **SQLite:** e.g. use `json_each(metadata, '$.tags')` and `value LIKE '%' || ? || '%'` in a subquery/exists.
- Add a **scope** on `Thought`, e.g. `scopeTagMatchesQuery(Builder $query, string $normalizedQuery): Builder`, that applies (exact OR contains) and is null-safe for missing `metadata` or `metadata->tags`. Reuse for web, API, and MCP.

### 3.2 Merge and pagination

- **Web:** IdeaController currently gets a paginator from `nearestWithin(...)->paginate(...)`. Change to: (1) fetch tag-matched thought ids (and ordered list) for the user/query; (2) run semantic query (no pagination on DB); (3) merge: tag list + semantic list with ids from (1) removed from semantic; (4) build a `LengthAwarePaginator` over the merged collection so existing AJAX pagination still works.
- **API / MCP:** Same merge logic; return first `limit` items of the merged list (no page param on MCP; API can add page later if needed).

### 3.3 Shared logic

- Consider a small **service or helper** (e.g. `ThoughtSearchService::search(string $query, int $userId, array $options)`) that returns merged thought list (and total) so IdeaController, ThoughtsApiController, and McpController all call one place. Options can include: limit, max distance, scopes (topLevel, excludingResearch), and whether to apply tag match. Keeps behaviour consistent and testable in one place.

### 3.4 Normalization and LIKE safety

- Normalize query once: `mb_strtolower(trim($query))`. For “contains” with LIKE, escape `%` and `_` in the bound value so the query is treated literally (e.g. replace `%` → `\%`, `_` → `\_` and use a parameter for the escaped string).

### 3.5 Tests

- **Unit/feature:** (1) Search with query that exactly matches a tag returns that thought in the result set (and at top when combined with semantic). (2) Search with query that is a substring of a tag (e.g. `project-spec`, tag `decision:project-spec`) returns that thought. (3) Tag-matched thoughts are deduped from semantic list (no duplicate in merged result). (4) Search with no tag match returns only semantic results (unchanged). (5) MCP `search_thoughts` with tag-matching query returns tag-matched thoughts first.
- **Existing tests:** Ensure existing search and MCP tests still pass; add or adjust for new merge behaviour.

## 4. Out of scope (v1)

- `match_kind` in API/MCP response.
- Separate “Tag matches” / “Semantic matches” sections in the UI.
- Re-embedding thoughts to include tags in the vector (semantic search remains content-based).

# Design: Working memory scopes index — performance (phases A → C)

**Status:** Draft — approved direction (batch resolution, then DB-side tag extraction)  
**Date:** 2026-05-08  
**Depends on:** [`2026-05-08-working-memory-index-design.md`](2026-05-08-working-memory-index-design.md)

## Problem

`/memory/scopes` builds rows via `WorkingMemoryScopesIndexBuilder`. For each **tag** scope row, `UserCanonicalTagResolver::resolve()` loads **all** `thoughts.metadata` values for the user and scans tags until a slug matches. **Each tag row repeats that full scan**, so cost grows as **O(tag rows × thoughts)** and dominates page load for users with many thoughts and multiple tag-scoped working memories.

## Goals

- Preserve **identical** canonical tag labels and fallbacks as today (`readableTagTitle` when no match).
- Ship improvements in **two phases**: **A** first (low risk, large win), **C** second (smaller payloads, better DB utilization).
- Keep **SQLite** (local/tests) and **PostgreSQL** (production) behavior aligned.

## Non-goals

- Caching layer (Redis) for resolver results — optional later.
- Changing how `scope_key` is stored or stream URL semantics.
- Pagination of the scopes index (still “all scopes that have a row”).

---

## Phase A — Batch canonical resolution (PHP, single full metadata read)

### Approach

1. Add **`resolveMany(int $userId, array $tagSlugs): array<string, string|null>`** on `UserCanonicalTagResolver` (or a dedicated small class used by the resolver). Returned map is keyed by **requested slug string** (same as `scope_key`); values are canonical label or `null` when unresolved.

2. Build an internal **`slug → canonical label`** map once per request:
   - Query `Thought::query()->where('user_id', $userId)->select('metadata')->orderBy('id')` (explicit order is **required** so “first winning tag” matches a stable definition; the current `resolve()` does not set `orderBy` and should be aligned when touching this code).
   - Reuse the same flattening rules as today: `metadata` → `tags` array → `flatten` → `unique` preserves first occurrence in iteration order; then assign each unique tag string to `TagSlug::from($tag)` if that slug key is not yet set (**first slug wins**), matching the current loop that returns the first matching tag in `$tags` iteration order.

3. For each requested `$tagSlug`, set result to the built map’s value for that slug, or `null`.

4. **`WorkingMemoryScopesIndexBuilder`:** collect **distinct** tag `scope_key` values from `$tagMemories`, call **`resolveMany` once**, pass results into row building (e.g. map into `titleFor` for tags).

### Success criteria

- **One** metadata-loading query per index request for tag resolution (plus existing working memory / project queries).
- Feature or unit test proving **no N× resolver scans** (e.g. assert query count or DB query log with multiple tag rows).
- Spot-check: labels match pre-change behavior for the same DB snapshot.

### Risks / notes

- Ordering semantics must be documented and tested so batch resolution does not accidentally reshuffle “canonical” labels.

---

## Phase C — Database-side tag extraction (narrow projection)

### Motivation

Phase A still transfers **every** `metadata` JSON blob for the user. Phase C keeps the **same slug→canonical map algorithm** but feeds it tag strings in **stable order** without loading full `metadata`.

### Approach

1. **Driver-specific** extraction queries that return ordered **(thought identifier, tag text)** rows — only thoughts that have non-null `metadata` with a `tags` array. Examples (exact SQL finalized during implementation; must be validated on SQLite **and** PostgreSQL):

   - **PostgreSQL:** unnest `metadata->'tags'` (or cast via `jsonb`) per row, with deterministic join order (e.g. order by `thoughts.id` and array position).
   - **SQLite:** `json_each` / `json_extract` on `metadata` for `$.tags`, constrained to `user_id`, ordered by `thoughts.id` and element order.

2. **PHP:** Walk the result set in order; for each tag string, if `TagSlug::from($tag)` is not yet in the working map, set it (same **first wins** rule as Phase A). Then satisfy `resolveMany` from that map.

3. **Fallback:** If a driver cannot be supported safely in one release window, gate Phase C on `pgsql`/`sqlite` only and throw or fall back to Phase A path for unknown drivers (should not occur in this repo’s deployment targets).

4. **Testing:** Integration tests on SQLite; CI or manual verification that PostgreSQL SQL matches. Consider a thin test that mocks connection driver and asserts the correct query branch is taken.

### Success criteria

- Resolver no longer selects the full `metadata` column for this path when Phase C is active.
- Canonical labels still match Phase A for the same dataset (golden cases or parallel comparison in a test harness if feasible).

### Risks / notes

- JSON shape variance (`metadata.tags` missing or non-array): queries must treat as empty; align with existing Thought normalization expectations.
- Expression indexes are **not** required for correctness; optional follow-up if profiling shows sequential scans on large tables.

---

## Testing strategy (both phases)

- **Unit:** Slug map builder given controlled metadata rows / extracted tag streams.
- **Feature:** Authenticated user with multiple tag scopes and many thoughts — assert bounded query count or absence of repeated full-metadata patterns.
- **Regression:** `MemoryScopesIndexTest` (and related) updated for new resolver API; preserve expectations on titles and URLs.

---

## Rollout

1. Implement and ship **Phase A** alone; verify in production-like data sizes.
2. Implement **Phase C** behind a clear internal boundary (e.g. private method `loadTagSlugMapForUser`) so Phase A remains the fallback if a query needs adjustment.

---

## References

- `App\Services\Tags\UserCanonicalTagResolver`
- `App\Services\WorkingMemory\WorkingMemoryScopesIndexBuilder`
- `App\Support\TagSlug`

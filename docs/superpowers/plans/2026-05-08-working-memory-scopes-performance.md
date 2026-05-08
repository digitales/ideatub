# Working memory scopes performance (A → C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Speed up `/memory/scopes` by eliminating per-tag full-table metadata scans: first batch canonical tag resolution in PHP (Phase A), then narrow DB projections for tag strings on SQLite and PostgreSQL (Phase C).

**Architecture:** Extend `UserCanonicalTagResolver` with `resolveMany()` that builds a slug→canonical map once per request using the same first-win semantics as `resolve()`. Update `WorkingMemoryScopesIndexBuilder` to call `resolveMany()` once for all tag scope keys. In Phase C, replace the metadata full-column scan with driver-specific SQL that yields ordered tag strings, then reuse the same map-building logic.

**Tech Stack:** Laravel 12, Pest, SQLite (tests), PostgreSQL (production).

**Spec:** [`docs/superpowers/specs/2026-05-08-working-memory-scopes-performance-design.md`](../specs/2026-05-08-working-memory-scopes-performance-design.md)

---

## File map

| File | Role |
| --- | --- |
| `app/Services/Tags/UserCanonicalTagResolver.php` | Add `resolveMany`; refactor `resolve` to delegate; Phase C: internal tag stream loader |
| `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php` | Collect tag slugs; single `resolveMany` call; thread map into titles |
| `tests/Unit/Services/Tags/UserCanonicalTagResolverTest.php` (create if missing) or extend existing | Unit tests for map semantics and ordering |
| `tests/Feature/MemoryScopesIndexTest.php` | Assert titles; optionally query count |
| Phase C: possible `tests/Unit/...` for SQL branches | Driver-specific behavior |

---

### Task 1: Phase A — `resolveMany` and builder wiring

**Files:**

- Modify: `app/Services/Tags/UserCanonicalTagResolver.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php`
- Modify or create: unit tests for resolver
- Modify: `tests/Feature/MemoryScopesIndexTest.php` if needed

- [ ] **Step 1:** Extract private method `buildSlugToCanonicalMap(int $userId): array<string, string>` that loads thoughts with `select('metadata')->where('user_id', $userId)->orderBy('id')`, flattens tags with same chain as current `resolve()`, then fills map: for each tag in iteration order after `unique()`, set `map[TagSlug::from($tag)] = $tag` only if key not present.

- [ ] **Step 2:** Implement `resolveMany(int $userId, array $tagSlugs): array<string, string|null>` returning an array keyed by each requested slug (use the requested string as key for lookup into map).

- [ ] **Step 3:** Change `resolve($userId, $slug)` to use `resolveMany($userId, [$slug])[$slug] ?? null` or the shared map helper (avoid double query if both are called in same request — not required for scopes page if only `resolveMany` is used).

- [ ] **Step 4:** In `WorkingMemoryScopesIndexBuilder`, before the tag section loop, compute `$tagSlugs = $tagMemories->pluck('scope_key')->unique()->values()->all()` and `$labels = $this->canonicalTagResolver->resolveMany($userId, $tagSlugs)`; pass `$labels` into `rowFor` / `titleFor` for tag rows.

- [ ] **Step 5:** Add unit tests: multiple slugs, first-win ordering, null fallback to `readableTagTitle` behavior unchanged.

- [ ] **Step 6:** Add or extend feature test: user with many thoughts and ≥2 tag memories — assert database query count does not scale with tag row count (e.g. `DB::listen` count cap, or `assertDatabaseQueryCount` pattern if available).

- [ ] **Step 7:** Run `php artisan test` for affected tests; run Pint if PHP changed.

- [ ] **Step 8:** Commit Phase A.

---

### Task 2: Phase C — DB tag extraction (SQLite + PostgreSQL)

**Files:**

- Modify: `app/Services/Tags/UserCanonicalTagResolver.php`
- Add: tests covering both drivers or SQLite + raw SQL snapshot test for PG in CI

- [ ] **Step 1:** Add private method `streamTagStringsForUser(int $userId): iterable<string>` (or return ordered array from query) with `match ($driver)` for `sqlite` and `pgsql`. Use parameterized bindings for `user_id`. Handle null/invalid metadata safely.

- [ ] **Step 2:** Replace inner data source of `buildSlugToCanonicalMap`: instead of loading full `metadata`, consume streamed tag strings in order and apply the same first-win map logic.

- [ ] **Step 3:** Verify on SQLite test suite; run PostgreSQL integration manually or add a test that runs only when `DB_CONNECTION=pgsql` if the project supports it.

- [ ] **Step 4:** Document exact SQL in code comments (short) with spec cross-reference.

- [ ] **Step 5:** Run full test suite; commit Phase C.

---

### Task 3: Documentation and cleanup

- [ ] **Step 1:** Update spec **Status** to `Implemented` when both phases ship.

- [ ] **Step 2:** Optional one-line link from [`2026-05-08-working-memory-index-design.md`](../specs/2026-05-08-working-memory-index-design.md) “Future enhancements” to this performance spec (if still accurate).

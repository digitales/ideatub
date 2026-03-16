# Search include tags — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the user (or MCP client) searches with a query that matches a thought’s tag (exact or substring), those thoughts appear first in results, then semantic matches; same behaviour on web, REST API, and MCP.

**Architecture:** Add a `Thought::scopeTagMatchesQuery` for tag matching (exact + contains, driver-safe). Introduce `ThoughtSearchService::search()` that runs tag query and semantic query, merges (tag first, dedupe), and returns a collection. IdeaController, ThoughtsApiController, and McpController call the service and keep existing response shapes. Pagination on web is in-memory over the merged list (capped fetch).

**Tech Stack:** Laravel 12, PHP 8.2+, PostgreSQL (pgvector) / SQLite, existing OpenRouterService for embeddings.

**Spec:** `docs/superpowers/specs/2026-03-16-search-include-tags-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Models/Thought.php` | Add `scopeTagMatchesQuery(Builder $query, string $normalizedQuery)`. Add static `escapeForLike(string $value): string` for LIKE safety. |
| `app/Services/ThoughtSearchService.php` | **Create.** `search(string $query, int $userId, array $options): array{thoughts: \Illuminate\Support\Collection, total: int}`. Options: limit, max_distance, tag_limit, semantic_limit. Runs tag query, semantic query (embed + nearestWithin/nearestTo), merge, return collection + total. |
| `app/Http/Controllers/IdeaController.php` | In the `$query !== ''` branch: call ThoughtSearchService with limit 20, max_distance 0.5; build LengthAwarePaginator from merged collection for current page; keep AJAX JSON and error handling. |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Inject ThoughtSearchService; in `search()` call service with request limit; return same JSON shape from `$result['thoughts']`. |
| `app/Http/Controllers/Api/McpController.php` | Inject ThoughtSearchService; in `searchThoughts()` call service; return same result shape. |
| `tests/Unit/ThoughtTagMatchesQueryTest.php` | **Create.** Test scope: exact tag match, tag contains query, no match, null/empty metadata. |
| `tests/Feature/SearchIncludeTagsTest.php` | **Create.** Test web: search with exact tag returns thought at top; search with tag substring; dedupe; no tag match returns semantic only. |
| `tests/Feature/ThoughtsApiTest.php` | Add test: search with tag-matching query returns tag-matched thought in results (and first when applicable). |
| `tests/Feature/McpApiTest.php` | Add test: search_thoughts with tag-matching query returns tag-matched thought first. |

---

## Chunk 1: Tag match scope and LIKE helper

### Task 1: Thought::scopeTagMatchesQuery and escapeForLike

**Files:**
- Modify: `app/Models/Thought.php`
- Test: `tests/Unit/ThoughtTagMatchesQueryTest.php` (create)

- [ ] **Step 1: Create test file and write failing tests**

Create `tests/Unit/ThoughtTagMatchesQueryTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtTagMatchesQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_tag_matches_query_exact_match(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec', 'work']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('decision:project-spec')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_tag_contains_query(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('project-spec')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_no_match_returns_empty(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['work']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('decision:other')
            ->get();

        $this->assertCount(0, $found);
    }

    public function test_scope_tag_matches_query_normalizes_query_to_lowercase(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('DECISION:PROJECT-SPEC')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_empty_metadata_tags_returns_empty(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => [],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('work')
            ->get();

        $this->assertCount(0, $found);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/ThoughtTagMatchesQueryTest.php --no-coverage`

Expected: FAIL (method/scope tagMatchesQuery does not exist).

- [ ] **Step 3: Add escapeForLike and scopeTagMatchesQuery to Thought**

In `app/Models/Thought.php`:

1. Add a static helper after `normalizeMetadataTags` (before the closing `}` of the class):

```php
/**
 * Escape % and _ for safe use in LIKE patterns. Use with parameter binding.
 */
public static function escapeForLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}
```

2. Add the scope before `scopeWithoutTags`:

```php
/**
 * Scope to thoughts that have at least one tag equal to the normalized query or containing it (substring).
 * Query must be normalized (trimmed, lowercase) by the caller.
 * Null-safe for missing metadata or metadata->tags.
 *
 * @param  Builder<Thought>  $query
 * @return Builder<Thought>
 */
public function scopeTagMatchesQuery(Builder $query, string $normalizedQuery): Builder
{
    if ($normalizedQuery === '') {
        return $query->whereRaw('0 = 1');
    }

    $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
    $likePattern = '%' . static::escapeForLike($normalizedQuery) . '%';

    if ($driver === 'pgsql') {
        // Exact: jsonb array contains the string. Contains: any element LIKE %query%.
        $query->where(function (Builder $q) use ($normalizedQuery, $likePattern): void {
            $q->whereJsonContains('metadata->tags', $normalizedQuery)
                ->orWhereRaw(
                    "EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(metadata->'tags', '[]'::jsonb)) AS t WHERE t LIKE ?)",
                    [$likePattern]
                );
        });

        return $query;
    }

    // SQLite: json_each(metadata, '$.tags') exposes key, value; use value for match.
    $query->where(function (Builder $q) use ($normalizedQuery, $likePattern): void {
        $q->whereRaw(
            "EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(metadata, '$.tags'), '[]')) WHERE value = ?)",
            [$normalizedQuery]
        )->orWhereRaw(
            "EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(metadata, '$.tags'), '[]')) WHERE value LIKE ?)",
            [$likePattern]
        );
    });

    return $query;
}
```

Ensure `use Illuminate\Database\Eloquent\Builder` is present at top of Thought.php if not already.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/ThoughtTagMatchesQueryTest.php --no-coverage`

Expected: PASS (all 5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Thought.php tests/Unit/ThoughtTagMatchesQueryTest.php
git commit -m "feat: add Thought::scopeTagMatchesQuery and escapeForLike for search-include-tags"
```

---

## Chunk 2: ThoughtSearchService and wire controllers

### Task 2: ThoughtSearchService

**Files:**
- Create: `app/Services/ThoughtSearchService.php`
- Test: `tests/Unit/ThoughtSearchServiceTest.php` (create)

- [ ] **Step 1: Write failing test for ThoughtSearchService::search**

Create `tests/Unit/ThoughtSearchServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\ThoughtSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_tag_matches_first_then_semantic(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $tagThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Unrelated content',
            'embedding' => array_fill(0, 1536, 0.5),
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);
        $semanticThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec is important',
            'embedding' => $embedding,
            'metadata' => ['tags' => []],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $service = app(ThoughtSearchService::class);
        $result = $service->search('decision:project-spec', $user->id, [
            'limit' => 10,
            'max_distance' => 0.6,
            'tag_limit' => 50,
            'semantic_limit' => 50,
        ]);

        $this->assertCount(2, $result['thoughts']);
        $this->assertSame(2, $result['total']);
        $ids = $result['thoughts']->pluck('id')->all();
        $this->assertSame($tagThought->id, $ids[0], 'Tag-matched thought should be first');
        $this->assertSame($semanticThought->id, $ids[1]);
    }

    public function test_search_dedupes_semantic_results_that_also_match_tag(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec content',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($embedding);
        });

        $service = app(ThoughtSearchService::class);
        $result = $service->search('decision:project-spec', $user->id, [
            'limit' => 10,
            'max_distance' => 0.6,
            'tag_limit' => 50,
            'semantic_limit' => 50,
        ]);

        $this->assertCount(1, $result['thoughts']);
        $this->assertSame(1, $result['total']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ThoughtSearchServiceTest.php --no-coverage`

Expected: FAIL (ThoughtSearchService not found or search() not defined).

- [ ] **Step 3: Implement ThoughtSearchService**

Create `app/Services/ThoughtSearchService.php`:

```php
<?php

namespace App\Services;

use App\Models\Thought;
use Illuminate\Support\Collection;

class ThoughtSearchService
{
    public function __construct(
        private OpenRouterService $openRouter,
    ) {}

    /**
     * Search thoughts: tag matches first (by created_at desc), then semantic (by distance). Dedupe by id.
     *
     * @param  array{limit?: int, max_distance?: float, tag_limit?: int, semantic_limit?: int}  $options
     * @return array{thoughts: Collection<int, Thought>, total: int}
     */
    public function search(string $query, int $userId, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 20);
        $maxDistance = (float) ($options['max_distance'] ?? 0.5);
        $tagLimit = (int) ($options['tag_limit'] ?? 100);
        $semanticLimit = (int) ($options['semantic_limit'] ?? 100);

        $normalizedQuery = mb_strtolower(trim($query));

        $baseQuery = Thought::query()->where('user_id', $userId);

        $tagMatches = collect();
        if ($normalizedQuery !== '') {
            $tagMatches = (clone $baseQuery)
                ->tagMatchesQuery($normalizedQuery)
                ->orderByDesc('created_at')
                ->limit($tagLimit)
                ->get();
        }

        $tagIds = $tagMatches->pluck('id')->flip()->all();

        $embedding = $this->openRouter->embed($query);
        $semanticQuery = (clone $baseQuery)
            ->whereNotNull('embedding')
            ->nearestWithin($embedding, $maxDistance)
            ->limit($semanticLimit);

        $semantic = $semanticQuery->get();

        if ($semantic->isEmpty()) {
            $semantic = (clone $baseQuery)
                ->whereNotNull('embedding')
                ->nearestTo($embedding, $semanticLimit)
                ->get();
        }

        $semanticFiltered = $semantic->reject(fn (Thought $t) => isset($tagIds[$t->id]));

        $merged = $tagMatches->concat($semanticFiltered)->take($limit);
        $total = $tagMatches->count() + $semanticFiltered->count();

        return [
            'thoughts' => $merged->values(),
            'total' => min($total, $tagLimit + $semanticLimit),
        ];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/ThoughtSearchServiceTest.php tests/Unit/ThoughtTagMatchesQueryTest.php --no-coverage`

Expected: PASS. Fix any failures (e.g. nearestWithin may return 0 if distance threshold is too strict; test uses 0.6 and same embedding for semantic thought so it should be within).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ThoughtSearchService.php tests/Unit/ThoughtSearchServiceTest.php
git commit -m "feat: add ThoughtSearchService for hybrid tag + semantic search"
```

### Task 3: Wire IdeaController

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`

- [ ] **Step 1: Inject ThoughtSearchService and use it in search branch**

In `app/Http/Controllers/IdeaController.php`:

1. Add to constructor: `private ThoughtSearchService $searchService` and `use App\Services\ThoughtSearchService`.
2. In the `if ($query !== '')` block, replace the block from `$embedding = ...` through the fallback paginator and the `if ($request->ajax())` return with:

- Get merged result from service with `limit: self::SEARCH_LIMIT`, `max_distance: self::SEARCH_MAX_DISTANCE`, `tag_limit: 100`, `semantic_limit: 100`.
- Build paginator: `$thoughts = new LengthAwarePaginator($result['thoughts'], $result['total'], self::SEARCH_LIMIT, $page, ['path' => $request->url(), 'query' => $request->query()]);`
- Keep the same `if ($request->ajax())` block (render view, return JSON with has_more, next_page, count).
- Keep the catch block (report, redirect with error).

Important: the service returns at most `limit` thoughts per call. For pagination we need to either (a) have the service accept a page/offset and return the right slice, or (b) pass a larger limit and slice in the controller. Spec says "fetch up to 100 tag + 100 semantic, merge, then paginate". So we need the service to return the full merged list (up to tag_limit + semantic_limit) and the controller to slice for the current page. Adjust the service to accept an optional `for_page` and `per_page` so we don't load all 200 every time, or keep it simple: service returns first `limit` (20) only; then for page 2 we'd need to call with offset. Simpler approach: service returns `thoughts` (collection) and `total` (total merged count). For web we need paginated slice. So service should return all merged thoughts up to (tag_limit + semantic_limit) so controller can paginate. Update the service to return the full merged collection (not ->take($limit)) and total; controller then does: `$merged = $result['thoughts']; $total = $merged->count(); $pageItems = $merged->slice(($page - 1) * self::SEARCH_LIMIT, self::SEARCH_LIMIT); $thoughts = new LengthAwarePaginator($pageItems, $total, self::SEARCH_LIMIT, $page, [...]);`

Revise ThoughtSearchService: return full merged collection (tag + semantic filtered) with ->take($tagLimit + $semanticLimit), and total = that count. No ->take($limit) inside service for web’s sake. Then controller: `$merged = $result['thoughts']; $total = $merged->count(); $pageItems = $merged->slice(($page - 1) * self::SEARCH_LIMIT, self::SEARCH_LIMIT)->values(); $thoughts = new LengthAwarePaginator($pageItems, $total, self::SEARCH_LIMIT, $page, ['path' => $request->url(), 'query' => $request->query()]);`

Update the plan’s service return: `thoughts` = full merged collection (tag + semantic, deduped), capped at tag_limit + semantic_limit items; `total` = that collection’s count. No `limit` applied inside service for the merged list (so web can paginate). Then in IdeaController we slice for the current page.

- [ ] **Step 2: Run existing IdeaPageTest**

Run: `php artisan test tests/Feature/IdeaPageTest.php --no-coverage`

Expected: PASS (test_idea_page_shows_search_results may need embedding mock; if it fails, ensure service is called and mock embed once).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "feat(web): use ThoughtSearchService for search (tag + semantic)"
```

### Task 4: Wire ThoughtsApiController and McpController

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1: ThoughtsApiController — use ThoughtSearchService**

In `app/Http/Controllers/Api/ThoughtsApiController.php`:
- Add `use App\Services\ThoughtSearchService` and inject `ThoughtSearchService $searchService` in constructor.
- In `search()`: call `$result = $this->searchService->search($query, (int) auth()->id(), ['limit' => $limit, 'max_distance' => 0.5, 'tag_limit' => 100, 'semantic_limit' => 100]);`. Then take first `$limit` from `$result['thoughts']`: `$thoughts = $result['thoughts']->take($limit)`.
- Build response from `$thoughts` (same map as before). Remove direct embed + nearestTo.

- [ ] **Step 2: McpController — use ThoughtSearchService**

In `app/Http/Controllers/Api/McpController.php`:
- Add ThoughtSearchService to constructor (and use statement).
- In `searchThoughts()`: call the service with `limit`, `max_distance` 0.5, same tag/semantic limits; return `$result['thoughts']->take($limit)` mapped to the same array shape. Remove direct embed + nearestTo.

- [ ] **Step 3: Run API and MCP tests**

Run: `php artisan test tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php --no-coverage`

Expected: PASS. Fix any failing tests (e.g. auth tests unchanged; if there is a test that asserts on search result structure, keep it).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/ThoughtsApiController.php app/Http/Controllers/Api/McpController.php
git commit -m "feat(api,mcp): use ThoughtSearchService for tag-influenced search"
```

---

## Chunk 3: Service return shape and controller pagination

Ensure ThoughtSearchService returns the **full** merged list (capped at tag_limit + semantic_limit) so that:
- Web can paginate by slicing: `$result['thoughts']` is the full merged collection; controller does `$total = $result['thoughts']->count(); $pageItems = $result['thoughts']->slice(($page - 1) * self::SEARCH_LIMIT, self::SEARCH_LIMIT)->values();` and builds LengthAwarePaginator from $pageItems and $total.
- API/MCP apply `->take($limit)` on the collection before mapping to response.

In `ThoughtSearchService::search`, do not call `->take($limit)` on the merged collection. Return:
`$merged = $tagMatches->concat($semanticFiltered);` (no take), and `'total' => $merged->count()`. Then IdeaController paginates over it; API/MCP take first `limit`.

- [ ] **Step 1: Update ThoughtSearchService to return full merged list**

In `app/Services/ThoughtSearchService.php`, replace:
`$merged = $tagMatches->concat($semanticFiltered)->take($limit);` and `'total' => min($total, ...)` with:
`$merged = $tagMatches->concat($semanticFiltered)->values();` and `'total' => $merged->count()`.

- [ ] **Step 2: Update IdeaController to slice for page**

In IdeaController search branch: `$result = $this->searchService->search(...)` with tag_limit 100, semantic_limit 100 (no limit in options, or pass limit only for API/MCP). Then:
`$all = $result['thoughts']; $total = $result['total']; $pageItems = $all->slice(($page - 1) * self::SEARCH_LIMIT, self::SEARCH_LIMIT)->values(); $thoughts = new LengthAwarePaginator($pageItems, $total, self::SEARCH_LIMIT, $page, ['path' => $request->url(), 'query' => $request->query()]);`

- [ ] **Step 3: Run full test suite for search and ideas**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/ThoughtsApiTest.php tests/Unit/ThoughtSearchServiceTest.php tests/Unit/ThoughtTagMatchesQueryTest.php --no-coverage`

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/ThoughtSearchService.php app/Http/Controllers/IdeaController.php
git commit -m "fix: return full merged list from ThoughtSearchService for web pagination"
```

---

## Chunk 4: Feature tests for search-include-tags

### Task 5: Feature tests

**Files:**
- Create: `tests/Feature/SearchIncludeTagsTest.php`
- Modify: `tests/Feature/ThoughtsApiTest.php`
- Modify: `tests/Feature/McpApiTest.php`

- [ ] **Step 1: Web — search with exact tag returns thought at top**

In `tests/Feature/SearchIncludeTagsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchIncludeTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_with_exact_tag_returns_tagged_thought_first(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $tagThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Content A',
            'embedding' => array_fill(0, 1536, 0.9),
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);
        $otherThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec is cool',
            'embedding' => $embedding,
            'metadata' => ['tags' => []],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'decision:project-spec']));

        $response->assertStatus(200);
        $thoughtIds = $response->viewData('thoughts')->items();
        $ids = array_map(fn ($t) => $t->id, $thoughtIds);
        $this->assertContains($tagThought->id, $ids);
        $this->assertSame($tagThought->id, $ids[0], 'Tag-matched thought should be first');
    }

    public function test_search_with_tag_substring_returns_matching_thought(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('project-spec')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'project-spec']));

        $response->assertStatus(200);
        $thoughts = $response->viewData('thoughts')->items();
        $this->assertCount(1, $thoughts);
        $this->assertSame('Some content', $thoughts[0]->content);
    }

    public function test_search_with_no_tag_match_returns_semantic_only(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Semantic content about pgvector',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['work']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('pgvector')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pgvector']));

        $response->assertStatus(200);
        $thoughts = $response->viewData('thoughts')->items();
        $this->assertCount(1, $thoughts);
        $this->assertSame('Semantic content about pgvector', $thoughts[0]->content);
    }
}
```

- [ ] **Step 2: Run SearchIncludeTagsTest**

Run: `php artisan test tests/Feature/SearchIncludeTagsTest.php --no-coverage`

Expected: PASS. If viewData('thoughts') is the paginator, use `$response->viewData('thoughts')->items()` (already in the plan).

- [ ] **Step 3: Add API test — tag match in results**

In `tests/Feature/ThoughtsApiTest.php`, add a test that creates a thought with a tag, mocks embed, calls GET /api/thoughts/search?query=<tag>, and asserts the thought is in the JSON and is first (or at least present).

- [ ] **Step 4: Add MCP test — search_thoughts tag match first**

In `tests/Feature/McpApiTest.php`, add a test that creates a thought with tag `decision:project-spec`, mocks embed, calls MCP search_thoughts with query `decision:project-spec`, and asserts result.thoughts[0].id equals that thought’s id.

- [ ] **Step 5: Run all related tests**

Run: `php artisan test tests/Feature/SearchIncludeTagsTest.php tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php tests/Feature/IdeaPageTest.php tests/Unit/ThoughtSearchServiceTest.php tests/Unit/ThoughtTagMatchesQueryTest.php --no-coverage`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/SearchIncludeTagsTest.php tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php
git commit -m "test: add search-include-tags feature tests for web, API, MCP"
```

---

## Summary

- **Chunk 1:** Thought::scopeTagMatchesQuery + escapeForLike, unit tests, commit.
- **Chunk 2:** ThoughtSearchService, unit tests; wire IdeaController, ThoughtsApiController, McpController; fix service to return full merged list and controller to paginate by slice; commit.
- **Chunk 3:** Confirm service return shape and web pagination; commit.
- **Chunk 4:** SearchIncludeTagsTest (web), API and MCP tests for tag-influenced search; commit.

After implementation, run full test suite and manual smoke test: web search with a tag, API search, MCP search_thoughts with a tag.

# Working Memory Index (All Scopes) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `GET /memory/scopes` listing every persisted `working_memories` row for the user, grouped and sorted per spec, with correct links, **Updating** / **Fallback** badges, and `build_started_at` lifecycle inside `WorkingMemoryBuilderService`.

**Architecture:** Add `build_started_at` on `working_memories`, set at the start of `WorkingMemoryBuilderService::build()` and cleared on successful version write, fallback return, or terminal failure. Add a small presenter that loads memories + projects + tag labels and returns sectioned rows for a Blade view. Reuse existing Memory styling and routes.

**Tech Stack:** Laravel 12, Blade, PHPUnit/Pest (match existing `WorkingMemoryWebTest` style), SQLite test DB.

**Spec:** [`docs/superpowers/specs/2026-05-08-working-memory-index-design.md`](../specs/2026-05-08-working-memory-index-design.md)

---

## File map

| File | Role |
| --- | --- |
| `database/migrations/2026_05_08_000000_add_build_started_at_to_working_memories_table.php` (new) | Nullable `build_started_at` |
| `app/Models/WorkingMemory.php` | `$fillable`, `casts` for `build_started_at` |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Lifecycle: set/clear `build_started_at`; reuse single `WorkingMemory` instance in transaction |
| `app/Services/WorkingMemory/WorkingMemoryScopeRowBadge.php` (new) | Pure static `label(?WorkingMemory $memory): ?string` |
| `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php` (new) | Builds grouped sections array for the index view |
| `app/Http/Controllers/MemoryScopesController.php` (new) | `index()` → view |
| `routes/web.php` | `GET /memory/scopes` inside existing `auth` + `working.memory.ui` group |
| `resources/views/memory/scopes/index.blade.php` (new) | Page UI |
| `resources/views/layouts/idea.blade.php` | Nav link **All memories** next to Memory |
| `resources/views/memory/show.blade.php` | Header link to index |
| `resources/views/memory/insights.blade.php` | Header link to index |
| `tests/Feature/MemoryScopesIndexTest.php` (new) | HTTP + grouping + badges |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeRowBadgeTest.php` (new) | Badge precedence |

---

### Task 1: Migration and model

**Files:**
- Create: `database/migrations/2026_05_08_000000_add_build_started_at_to_working_memories_table.php`
- Modify: `app/Models/WorkingMemory.php`

- [ ] **Step 1: Add migration**

Create migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memories', function (Blueprint $table): void {
            $table->timestamp('build_started_at')->nullable()->after('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('working_memories', function (Blueprint $table): void {
            $table->dropColumn('build_started_at');
        });
    }
};
```

If `after('last_refreshed_at')` fails on SQLite in CI, drop the `->after()` clause.

- [ ] **Step 2: Run migration**

Run: `php artisan migrate --no-interaction`  
Expected: completes without error.

- [ ] **Step 3: Update `WorkingMemory` model**

Add to `$fillable`: `'build_started_at'`.

Add to `casts()`:

```php
'build_started_at' => 'datetime',
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_08_000000_add_build_started_at_to_working_memories_table.php app/Models/WorkingMemory.php
git commit -m "feat(working-memory): add build_started_at to working_memories"
```

---

### Task 2: `WorkingMemoryBuilderService` build lifecycle

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`

- [ ] **Step 1: Refactor `build()` to own one `WorkingMemory` row from the start**

Immediately after `[$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize(...)`, add:

```php
        $memory = WorkingMemory::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'scope_type' => $normalizedScopeType,
                'scope_key' => $normalizedScopeKey,
            ],
            [
                'freshness_state' => 'stale',
            ]
        );

        $memory->forceFill(['build_started_at' => now()])->save();
```

Pass `$memory` into the `DB::transaction` closure (add to `use` list). **Remove** the inner `firstOrCreate` inside the transaction and use `$memory->versions()->create(...)` instead.

In the transaction’s `forceFill` for `$memory`, include:

```php
                'build_started_at' => null,
```

- [ ] **Step 2: Clear flag on `lastKnownGoodVersion` path**

In `lastKnownGoodVersion`, change the `forceFill` on `$memory` to also set `'build_started_at' => null` when marking degraded.

- [ ] **Step 3: Clear flag when rethrowing after no fallback**

In the `catch (RuntimeException $e)` block, after `if ($fallbackVersion !== null) { return $fallbackVersion; }`, clear `build_started_at` for that scope before `throw $e`:

```php
            WorkingMemory::query()
                ->where('user_id', $userId)
                ->where('scope_type', $normalizedScopeType)
                ->where('scope_key', $normalizedScopeKey)
                ->update(['build_started_at' => null]);
```

- [ ] **Step 4: Run existing working memory tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/WorkingMemoryRefreshFeatureTest.php`  
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php
git commit -m "feat(working-memory): track build_started_at during consolidated/incremental builds"
```

---

### Task 3: Badge helper (unit tests first)

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryScopeRowBadge.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeRowBadgeTest.php`

- [ ] **Step 1: Write failing unit tests**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryScopeRowBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryScopeRowBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_updating_when_build_started_at_is_set(): void
    {
        $memory = WorkingMemory::factory()->create([
            'build_started_at' => now(),
        ]);
        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'fallback',
        ]);
        $memory->update(['latest_version_id' => $memory->versions()->first()->id]);

        $this->assertSame('Updating', WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }

    public function test_returns_fallback_when_not_building_and_latest_is_fallback(): void
    {
        $memory = WorkingMemory::factory()->create([
            'build_started_at' => null,
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'fallback',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $this->assertSame('Fallback', WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }

    public function test_returns_null_when_validated(): void
    {
        $memory = WorkingMemory::factory()->create(['build_started_at' => null]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'authoring_status' => 'validated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $this->assertNull(WorkingMemoryScopeRowBadge::label($memory->fresh(['latestVersion'])));
    }
}
```

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryScopeRowBadgeTest.php`  
Expected: FAIL (class missing).

- [ ] **Step 2: Implement helper**

```php
<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;

final class WorkingMemoryScopeRowBadge
{
    public static function label(WorkingMemory $memory): ?string
    {
        if ($memory->build_started_at !== null) {
            return 'Updating';
        }

        $status = $memory->latestVersion?->authoring_status;

        if ($status === 'fallback') {
            return 'Fallback';
        }

        return null;
    }
}
```

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryScopeRowBadgeTest.php`  
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryScopeRowBadge.php tests/Unit/Services/WorkingMemory/WorkingMemoryScopeRowBadgeTest.php
git commit -m "feat(working-memory): badge labels for memory index rows"
```

---

### Task 4: Index presenter + controller + route + view

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php`
- Create: `app/Http/Controllers/MemoryScopesController.php`
- Create: `resources/views/memory/scopes/index.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Implement `WorkingMemoryScopesIndexBuilder`**

Constructor-inject `UserCanonicalTagResolver`. Public method:

```php
    /**
     * @return array{
     *   sections: list<array{key: string, title: string, rows: list<array{
     *     title: string,
     *     subtitle: string|null,
     *     href: string|null,
     *     badge: string|null,
     *     freshness: string|null,
     *     aria_label: string
     *   }>}
     * }
     */
    public function build(int $userId): array
```

Implementation outline:

1. `$memories = WorkingMemory::query()->where('user_id', $userId)->with('latestVersion')->get();`
2. If empty, return `['sections' => []];`
3. Partition collection by `scope_type` into `global`, `insights`, `project`, `tag`.
4. Sort each partition by `last_refreshed_at` descending (nulls last). Use `sortByDesc` with callback that maps null to `Carbon::minValue()` or sort twice.
5. Projects: `$projectIds = $projectMemories->pluck('scope_key')->unique()->values();` then `Project::query()->whereIn('id', $projectIds)->get()->keyBy(fn ($p) => mb_strtolower((string) $p->getKey()))` — adjust if project keys are UUID strings (match `MemoryController` project scope_key source).
6. For each memory row, compute:
   - **title:** global → `Global`; insights → `Insights`; project → `$projects[$key]->title ?? 'Unavailable project'`; tag → `$this->canonicalTagResolver->resolve($userId, $scopeKey) ?? ucfirst(str_replace('-', ' ', $scopeKey))`
   - **href:** global → `route('memory.show')`; insights → `route('memory.insights')`; project → `$project ? route('projects.memory.show', $project) : route('projects.index')` (orphan safe hub); tag → `route('memory.tag.show', ['tag' => $scopeKey])`
   - **badge:** `WorkingMemoryScopeRowBadge::label($memory)`
   - **subtitle / freshness:** reuse wording from `working-memory-module` pattern: `ucfirst($memory->freshness_state)` plus optional `· refreshed …` via `$memory->last_refreshed_at?->diffForHumans()`
   - **aria_label:** `"{$title} working memory"` + append badge text if present

7. Build `$sections` array only for non-empty partitions, in order Global → Insights → Projects → Tags, each with `key` (`global`|`insights`|`projects`|`tags`), human `title` (`Global`, `Insights`, `Projects`, `Tags`), and `rows`.

- [ ] **Step 2: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Services\WorkingMemory\WorkingMemoryScopesIndexBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryScopesController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryScopesIndexBuilder $indexBuilder,
    ) {}

    public function index(Request $request): View
    {
        $data = $this->indexBuilder->build((int) $request->user()->id);

        return view('memory.scopes.index', $data);
    }
}
```

Register view namespace is `memory.scopes.index` → file `resources/views/memory/scopes/index.blade.php`.

- [ ] **Step 3: Route**

In `routes/web.php`, inside `Route::middleware(['auth', 'working.memory.ui'])->group`, add before parameterized `/memory/{scopeType}/`:

```php
        Route::get('/memory/scopes', [MemoryScopesController::class, 'index'])->name('memory.scopes.index');
```

Add `use App\Http\Controllers\MemoryScopesController;` at top of `web.php` if not present.

- [ ] **Step 4: Blade view**

`@extends('layouts.idea')`, `@section('title', 'All memories — IdeaTub')`, max-width container matching `memory/insights.blade.php` (`max-w-3xl mx-auto px-6 pt-12 pb-24`).

Structure:

- H1 **All memories**, short description line.
- If `empty($sections)`, show empty state: paragraph + link `route('memory.show')` “Open global working memory”.
- Else foreach `$sections`: `<h2>` section title, then `<ul class="space-y-2">` of rows.
- Each row: if `href` is null (should not happen with current rules except optionally force null for orphan — spec uses projects index href), render `<div>`; else `<a href="..." class="block rounded-xl border ...">` containing title, optional `<span>` badges (`Updating` = teal border, `Fallback` = amber), freshness line.

Mirror Tailwind classes from `memory/insights.blade.php` card styling for consistency.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php app/Http/Controllers/MemoryScopesController.php resources/views/memory/scopes/index.blade.php routes/web.php
git commit -m "feat(working-memory): all memories index page"
```

---

### Task 5: Navigation and cross-links

**Files:**
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `resources/views/memory/show.blade.php`
- Modify: `resources/views/memory/insights.blade.php`

- [ ] **Step 1: Primary nav (desktop + mobile)**

Inside both `@if (config('features.working_memory_ui'))` blocks where the Memory link appears, add immediately after the Memory anchor:

```blade
<a href="{{ route('memory.scopes.index') }}" class="{{ $navLinkClass }}">
    All memories
</a>
```

Use the same `$navLinkClass` as neighboring links.

- [ ] **Step 2: Memory show header**

In the header flex next to Insights (global scope only), add a link to `memory.scopes.index` with label **All memories**, same button classes as the Insights link (`text-xs font-medium ...`). Guard with `@if (Route::has('memory.scopes.index'))` only if needed (route always exists when feature ships).

- [ ] **Step 3: Insights header**

Next to the existing “Working memory” back link, add **All memories** → `route('memory.scopes.index')` using the same visual style as other small header links on that page.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/idea.blade.php resources/views/memory/show.blade.php resources/views/memory/insights.blade.php
git commit -m "feat(ui): link All memories from nav and memory pages"
```

---

### Task 6: Feature tests

**Files:**
- Create: `tests/Feature/MemoryScopesIndexTest.php`

- [ ] **Step 1: Write feature tests**

Use `RefreshDatabase`, `withoutVite()`, `config(['features.working_memory_ui' => true])`, `actingAs($user)`.

**Test A — `test_guest_redirects_to_login`:** `get(route('memory.scopes.index'))` → redirect login.

**Test B — `test_flag_off_returns_404`:** feature off, authenticated → 404.

**Test C — `test_empty_state_shows_copy`:** user with no `WorkingMemory` rows → 200, assertSee `All memories`, assertSee anchor to `memory.show`.

**Test D — `test_sections_ordered_and_sorted`:** Create user, four `WorkingMemory` rows: global, insights, two projects (different `last_refreshed_at`). Use factories + attach `WorkingMemoryVersion` + set `latest_version_id`. GET index → assert response content order: section titles appear as Global, Insights, Projects; within Projects, row with newer `last_refreshed_at` appears before older (assert using `assertSeeInOrder` or position checks).

**Test E — `test_updating_badge`:** one memory with `build_started_at` set → assertSee `Updating`.

**Test F — `test_fallback_badge`:** `build_started_at` null, version `authoring_status` fallback → assertSee `Fallback`.

**Test G — `test_orphan_project_links_to_projects_index`:** `WorkingMemory` project scope with random UUID scope_key with no `Project` row → response contains `route('projects.index')` and “Unavailable” title pattern.

Use PHPUnit assertions consistent with `WorkingMemoryWebTest`.

Run: `php artisan test tests/Feature/MemoryScopesIndexTest.php`  
Expected: PASS.

- [ ] **Step 2: Full regression slice**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php tests/Unit/Services/WorkingMemory/`  
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/MemoryScopesIndexTest.php
git commit -m "test: memory scopes index page"
```

---

### Task 7: Documentation touch-up

**Files:**
- Modify: `docs/superpowers/specs/2026-05-08-working-memory-index-design.md`

- [ ] **Step 1: Set spec status to Approved (or Implementation pending)**

Change header **Status:** line to `Approved — implementation in progress` once code merges.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-08-working-memory-index-design.md
git commit -m "docs: mark working memory index spec approved"
```

---

## Plan self-review

| Spec requirement | Task coverage |
| --- | --- |
| Route `GET /memory/scopes`, middleware | Task 4 |
| List only existing `working_memories` | Task 4 (query all for user) |
| Grouping / sort | Task 4 |
| Links to existing detail routes | Task 4 |
| Orphan project safe link | Task 4 + Task 6 G |
| `build_started_at` migration + lifecycle | Tasks 1–2 |
| Updating / Fallback badges + precedence | Tasks 3–4, 6 |
| Nav + memory + insights links | Task 5 |
| Feature tests | Task 6 |

**Placeholder scan:** None intentional; adjust `Project::whereIn` key handling if tests reveal UUID vs string mismatch.

**Type consistency:** `WorkingMemoryScopesIndexBuilder::build` return shape must match Blade expectations (`sections` key).

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-08-working-memory-index.md`. Two execution options:

**1. Subagent-driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach do you want?

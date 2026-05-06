# Working Memory UI + Phase 3 Overlay + Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship authenticated web UI for global and project working memory (with Phase 3 overlay presentation), add `/memory/insights` for corpus-wide research-heavy signals, expose consolidation-window override in Settings (deployment default unchanged), and extend the working-memory JSON payload for drawers and Details panels—behind feature flags.

**Architecture:** Reuse `WorkingMemoryAssembler` / `WorkingMemoryBuilderService` with a small **consolidation-window resolver** backed by **`UserPreference`** (same pattern as ideas-to-revisit). Split **canonical consolidated snapshot** vs **latest incremental** when building the read payload so the UI can render baseline + drawer. Add thin **web controllers** + Blade views (`layouts.idea`). Insights use a dedicated **`MemoryInsightsService`** (heuristic v1, optional LLM behind config) with **no new SQL tables**—cache with `Cache::remember` for expensive paths.

**Tech Stack:** Laravel 12, Blade, Alpine.js (already on `layouts.idea`), `Illuminate\Support\Str::markdown()` for safe rendering, Pest/PHPUnit, existing `UserPreference`, existing working-memory tables.

---

## File structure (creates + touches)

| Path | Responsibility |
|------|----------------|
| `config/features.php` | Flags `working_memory_ui`, `working_memory_insights`. |
| `config/working_memory.php` | Add optional `insights_model_enabled` (bool, default false). |
| `app/Models/UserPreference.php` | Constant `KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS`. |
| `app/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolver.php` | Resolve effective window days (preference → config). |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Use resolver in consolidated window filter; accept optional `User` for preference resolution via user id lookup internally. |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Prefer latest **consolidated** version for canonical markdown; attach overlay + metadata fields for API/web. |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Return extended payload keys from assembler (non-breaking additions). |
| `app/Http/Controllers/MemoryController.php` | `show` (global), optional `projectEmbed` fragment if needed. |
| `app/Http/Controllers/MemoryInsightsController.php` | `show` for `/memory/insights`. |
| `app/Http/Controllers/WorkingMemorySettingsController.php` | GET/PUT consolidation window preference. |
| `app/Services/WorkingMemory/MemoryInsightsService.php` | Build insights DTO from research-classified thoughts. |
| `app/Http/Middleware/EnsureWorkingMemoryUiEnabled.php` | Abort 404 when UI flag off (register alias `working.memory.ui`). |
| `routes/web.php` | Routes `/memory`, `/memory/insights`, `/settings/working-memory`; middleware on group. |
| `resources/views/memory/show.blade.php` | Global memory page + drawer + Details. |
| `resources/views/memory/insights.blade.php` | Insights page. |
| `resources/views/settings/working-memory.blade.php` | Consolidation window form. |
| `resources/views/projects/partials/working-memory-module.blade.php` | Project scoped module. |
| `resources/views/idea/partials/working_memory_home_strip.blade.php` | Home teaser strip. |
| `resources/views/layouts/idea.blade.php` | Nav links + mobile menu entries behind `@if(config('features.working_memory_ui'))`. |
| `resources/views/idea/index.blade.php` | `@include` home strip when flag on. |
| `resources/views/projects/show.blade.php` | Include WM module partial. |
| `app/Http/Controllers/ProjectController.php` | Pass `$workingMemoryPreview` or flag-only (minimal: pass scope key + link). |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolverTest.php` | Resolver tests. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerOverlayTest.php` | Overlay + consolidated preference tests. |
| `tests/Feature/WorkingMemoryWebTest.php` | Auth + flags + routes. |
| `tests/Feature/WorkingMemorySettingsTest.php` | Settings form persistence. |
| `tests/Feature/MemoryInsightsWebTest.php` | Insights auth + flag. |
| `tests/Unit/Config/FeaturesConfigTest.php` | Assert new keys resolve (extend existing test). |

---

### Task 1: Feature flags and config

**Files:**
- Modify: `config/features.php`
- Modify: `config/working_memory.php`
- Modify: `tests/Unit/Config/FeaturesConfigTest.php`

- [ ] **Step 1: Write failing assertion for new feature keys**

Add to `tests/Unit/Config/FeaturesConfigTest.php`:

```php
#[Test]
public function working_memory_feature_keys_exist(): void
{
    $this->assertIsBool(config('features.working_memory_ui'));
    $this->assertIsBool(config('features.working_memory_insights'));
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `php artisan test tests/Unit/Config/FeaturesConfigTest.php --filter=working_memory_feature_keys_exist`

Expected: FAIL (undefined config keys).

- [ ] **Step 3: Add config entries**

`config/features.php` additions:

```php
    'working_memory_ui' => env('FEATURE_WORKING_MEMORY_UI', false),
    'working_memory_insights' => env('FEATURE_WORKING_MEMORY_INSIGHTS', false),
```

`config/working_memory.php` additions (merge into returned array):

```php
    'insights_model_enabled' => env('WORKING_MEMORY_INSIGHTS_MODEL_ENABLED', false),
```

- [ ] **Step 4: Re-run test**

Run: `php artisan test tests/Unit/Config/FeaturesConfigTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/features.php config/working_memory.php tests/Unit/Config/FeaturesConfigTest.php
git commit -m "feat(config): flags for working memory UI and insights"
```

---

### Task 2: Consolidation window resolver + UserPreference key

**Files:**
- Modify: `app/Models/UserPreference.php`
- Create: `app/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolver.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolverTest.php`

- [ ] **Step 1: Add preference constant**

In `UserPreference.php` after existing constants:

```php
    public const KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS = 'working_memory_consolidation_window_days';
```

- [ ] **Step 2: Create resolver**

`app/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolver.php`:

```php
<?php

namespace App\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;

class WorkingMemoryConsolidationWindowResolver
{
    public function effectiveDaysForUserId(int $userId): int
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return $this->configuredDefault();
        }

        $raw = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS);
        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            $days = (int) $raw;

            return max(1, min(3650, $days));
        }

        return $this->configuredDefault();
    }

    public function configuredDefault(): int
    {
        return max(1, (int) config('working_memory.consolidation_window_days', 180));
    }
}
```

- [ ] **Step 3: Write unit tests**

`tests/Unit/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolverTest.php`:

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryConsolidationWindowResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_config_when_preference_missing(): void
    {
        config(['working_memory.consolidation_window_days' => 90]);
        $user = User::factory()->create();

        $days = app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id);

        $this->assertSame(90, $days);
    }

    #[Test]
    public function it_uses_numeric_preference_when_set(): void
    {
        config(['working_memory.consolidation_window_days' => 90]);
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, 45);

        $days = app(WorkingMemoryConsolidationWindowResolver::class)->effectiveDaysForUserId((int) $user->id);

        $this->assertSame(45, $days);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolverTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/UserPreference.php app/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolver.php tests/Unit/Services/WorkingMemory/WorkingMemoryConsolidationWindowResolverTest.php
git commit -m "feat(memory): resolve consolidation window from user preference"
```

---

### Task 3: Builder uses resolver for consolidated window

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

- [ ] **Step 1: Inject resolver in constructor**

Add constructor dependency:

```php
    public function __construct(
        private readonly WorkingMemoryAssembler $assembler,
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
        private readonly WorkingMemoryConsolidationWindowResolver $consolidationWindowResolver,
    ) {}
```

- [ ] **Step 2: Replace inline config days with resolver**

In `selectThoughts()`, consolidated branch:

```php
        if ($buildType === 'consolidated') {
            $days = $this->consolidationWindowResolver->effectiveDaysForUserId($userId);
            $cutoff = now()->subDays($days);
            // ... same filter as today ...
        }
```

- [ ] **Step 3: Add test proving preference shrinks consolidated inputs**

Extend `WorkingMemoryBuilderServiceTest` with a test that sets `UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS` to `30`, creates two thoughts at 40 and 5 days ago, asserts only one input on consolidated build (mirror structure of existing window test).

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php
git commit -m "feat(memory): apply user consolidation window in builder"
```

---

### Task 4: Assembler — consolidated canonical body + overlay payload fields

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerOverlayTest.php`

**Contract additions (backward compatible):** extend arrays returned by `forScope` with:

- `last_refreshed_at` — ISO8601 string or null from `working_memories.last_refreshed_at`
- `effective_consolidation_window_days` — int from `WorkingMemoryConsolidationWindowResolver`
- `baseline_build_type` — `'consolidated'` or `'incremental'` depending on which version supplied the canonical markdown
- `canonical_summary_markdown` — alias equal to today’s `summary_markdown` for clarity (optional; if redundant, skip and document that `summary_markdown` is canonical consolidated-first)
- `overlay_deltas` — list of `{ "label": string, "detail": string, "since": string|null }` derived from the latest **incremental** version’s JSON sections (e.g. first 5 `active_threads` titles + truncated content links)
- `input_count` — count of `working_memory_inputs` for the canonical version

**Read path rule:** Load `WorkingMemory` with `versions` relation or targeted queries:

1. `latestConsolidated = versions()->where('build_type','consolidated')->orderByDesc('created_at')->first()`
2. `latestIncremental = versions()->where('build_type','incremental')->orderByDesc('created_at')->first()`
3. Canonical markdown + structured fields come from `latestConsolidated` if present; otherwise fall back to `latestVersion` (current behavior).
4. `overlay_deltas` built only when `latestIncremental` is **newer** than `latestConsolidated` (by `created_at`) or when consolidated is missing but incremental exists.

- [ ] **Step 1: Write failing API feature assertion**

In `tests/Feature/WorkingMemoryApiTest.php`, add test hitting `GET /api/thoughts/working-memory` expecting JSON keys `last_refreshed_at`, `effective_consolidation_window_days`, `overlay_deltas`, `input_count`.

Expected before implementation: missing keys → FAIL.

- [ ] **Step 2: Implement assembler changes**

Inject `WorkingMemoryConsolidationWindowResolver`. Implement private helpers:

- `resolveCanonicalVersion(WorkingMemory $memory): WorkingMemoryVersion`
- `buildOverlayDeltas(?WorkingMemoryVersion $incremental, ?WorkingMemoryVersion $consolidated): array`

Update `forScope` return shape; keep existing keys stable.

- [ ] **Step 3: Run focused tests**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerOverlayTest.php`

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Feature/WorkingMemoryApiTest.php tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerOverlayTest.php
git commit -m "feat(memory): consolidated-first read payload with overlay metadata"
```

---

### Task 5: Settings — consolidation window preference UI

**Files:**
- Create: `app/Http/Controllers/WorkingMemorySettingsController.php`
- Create: `resources/views/settings/working-memory.blade.php`
- Modify: `routes/web.php` (auth group)
- Modify: `resources/views/settings/profile.blade.php` or settings index hub if present — add link “Working memory”

Controller sketch:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkingMemorySettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $raw = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS);

        return view('settings.working-memory', [
            'effectiveDays' => app(\App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver::class)
                ->effectiveDaysForUserId((int) $user->id),
            'overrideDays' => ($raw !== null && $raw !== '' && is_numeric($raw)) ? (int) $raw : null,
            'defaultDays' => app(\App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver::class)->configuredDefault(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'working_memory_consolidation_window_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $user = $request->user();
        $value = $validated['working_memory_consolidation_window_days'] ?? null;

        if ($value === null) {
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS)
                ->delete();
        } else {
            UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, (int) $value);
        }

        return redirect()->route('settings.working-memory.index')->with('success', 'Working memory settings saved.');
    }
}
```

Blade: match `resources/views/settings/ideas-revisit.blade.php` styling patterns (form, CSRF, errors).

Routes:

```php
    Route::get('/settings/working-memory', [WorkingMemorySettingsController::class, 'index'])->name('settings.working-memory.index');
    Route::put('/settings/working-memory', [WorkingMemorySettingsController::class, 'update'])->name('settings.working-memory.update');
```

- [ ] **Step 1: Feature test `tests/Feature/WorkingMemorySettingsTest.php`** — guest redirected to login; authenticated GET 200; PUT clears preference when empty; PUT sets preference.

- [ ] **Step 2: Run tests**

Run: `php artisan test tests/Feature/WorkingMemorySettingsTest.php`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/WorkingMemorySettingsController.php resources/views/settings/working-memory.blade.php routes/web.php resources/views/settings/profile.blade.php
git commit -m "feat(settings): working memory consolidation window override"
```

---

### Task 6: Middleware + web routes for Memory pages

**Files:**
- Create: `app/Http/Middleware/EnsureWorkingMemoryUiEnabled.php`
- Modify: `bootstrap/app.php` or `app/Http/Kernel.php` (whichever this Laravel version uses) — register middleware alias
- Create: `app/Http/Controllers/MemoryController.php`
- Create: `resources/views/memory/show.blade.php`
- Modify: `routes/web.php`

Middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkingMemoryUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.working_memory_ui')) {
            abort(404);
        }

        return $next($request);
    }
}
```

`MemoryController@show` (global only in this task):

- Middleware `auth` + `working.memory.ui`.
- Call assembler `forScope($request->user()->id, 'global', 'global')`.
- Return `memory.show` with payload array.

**Task 8** adds `MemoryController@showProject(Project $project)` and route `GET /projects/{project}/memory` reusing the same Blade with project scope (`scope_type=project`, `scope_key=(string) $project->id`).

Blade requirements:

- Extend `layouts.idea`
- Header: title, freshness pill (color by state), **Details** `<details>` or Alpine toggle with confidence, `last_refreshed_at`, effective window days, input count, baseline build type
- Body: `{!! Str::markdown($summary_markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}` — **only** after trusting server-generated markdown; this matches `projects/show.blade.php`
- Drawer: Alpine `x-data="{ open: false }"` — button “Recent updates”; panel lists `overlay_deltas`; on small screens stack below with `lg:border-l` pattern
- Link to `route('memory.insights')` when `config('features.working_memory_insights')`

Routes:

```php
Route::middleware(['auth', 'working.memory.ui'])->group(function () {
    Route::get('/memory', [MemoryController::class, 'show'])->name('memory.show');
});
```

Register alias `working.memory.ui` in Laravel 11+ `bootstrap/app.php` `$middleware->alias([...])`.

- [ ] **Step 1: Feature test `tests/Feature/WorkingMemoryWebTest.php`** — flag false → 404; flag true → 200 and sees “Working memory”; guest → redirect login.

- [ ] **Step 2: Run tests**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/EnsureWorkingMemoryUiEnabled.php bootstrap/app.php app/Http/Controllers/MemoryController.php resources/views/memory/show.blade.php routes/web.php tests/Feature/WorkingMemoryWebTest.php
git commit -m "feat(ui): global working memory page behind feature flag"
```

---

### Task 7: Insights page + service

**Files:**
- Create: `app/Services/WorkingMemory/MemoryInsightsService.php`
- Create: `app/Http/Middleware/EnsureWorkingMemoryInsightsEnabled.php` (same as UI middleware but checks `config('features.working_memory_insights')`)
- Create: `app/Http/Controllers/MemoryInsightsController.php`
- Create: `resources/views/memory/insights.blade.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` — register alias `working.memory.insights`

Middleware registers **404** when the insights flag is off.

Route sketch:

```php
Route::middleware(['auth', 'working.memory.insights'])->group(function () {
    Route::get('/memory/insights', [MemoryInsightsController::class, 'show'])->name('memory.insights');
});
```

Service sketch (heuristic v1):

- Query `Thought::query()->where('user_id', $userId)->visibleInStream()->orderByDesc('created_at')->limit(300)` then **filter** with `ThoughtTypeNavigation::resolveTypeKey($thought) === 'research'` **or** `data_get($thought->metadata, 'type')` normalized to `research`.
- Build `$themes` from tag frequency across those thoughts (reuse tag counting similar to assembler).
- `summary_markdown`: sections `## Themes`, `## Notable captures` with bullet list of top 8 titles (Str::limit).
- If `config('working_memory.insights_model_enabled')` and OpenRouter configured, optional `## Commentary` via existing `OpenRouterService` with short prompt — **catch failures** and omit section.

- [ ] **Step 1: Feature test `tests/Feature/MemoryInsightsWebTest.php`**

- [ ] **Step 2: Implement**

- [ ] **Step 3: Run tests**

Run: `php artisan test tests/Feature/MemoryInsightsWebTest.php`

- [ ] **Step 4: Commit**

```bash
git add app/Services/WorkingMemory/MemoryInsightsService.php app/Http/Controllers/MemoryInsightsController.php app/Http/Middleware/EnsureWorkingMemoryInsightsEnabled.php resources/views/memory/insights.blade.php routes/web.php bootstrap/app.php tests/Feature/MemoryInsightsWebTest.php
git commit -m "feat(ui): memory insights page for research-heavy corpus"
```

---

### Task 8: Navigation + home strip + project module

**Files:**
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `resources/views/idea/index.blade.php`
- Create: `resources/views/idea/partials/working_memory_home_strip.blade.php`
- Modify: `resources/views/projects/show.blade.php`
- Modify: `app/Http/Controllers/ProjectController.php`

- [ ] **Step 1: Nav links**

Inside desktop and mobile nav blocks, when `config('features.working_memory_ui')`, add anchor `route('memory.show')` labeled **Memory**. When insights flag on, optional nested link is unnecessary—Insights linked from memory page header.

- [ ] **Step 2: Home strip partial**

Pass `@php($workingMemoryUi = config('features.working_memory_ui'))` or compute in controller—prefer `@includeWhen(config('features.working_memory_ui'), 'idea.partials.working_memory_home_strip')` and inside partial call assembler **only if** cheap: to avoid double DB on home, use **lightweight** approach: show static teaser + freshness via single `WorkingMemory` row query **or** link-only strip (“Open working memory”) without payload for v1. **Plan default:** link-only strip + one query for `freshness_state` on global memory if record exists; else “Not built yet”.

Implement `WorkingMemory::query()->where('user_id', auth()->id())->where('scope_type','global')->where('scope_key','global')->first()` in partial via `@php` block or small `ViewComposer`—YAGNI: use inline `@php` in partial for v1.

- [ ] **Step 3: Project module**

On `projects/show`, `@includeWhen(config('features.working_memory_ui'), 'projects.partials.working-memory-module', ['project' => $project])`  
Partial contains summary link `route('memory.show')` **wrong** — need **project scope URL**. Add **`MemoryController@showProject`** OR query param `?scope_type=project&scope_key=` — cleaner REST: **`GET /projects/{project}/memory`** route name `projects.memory.show` rendering same blade with scoped payload.

Add route + controller method:

```php
public function showProject(Project $project): View
{
    $this->authorize('view', $project);
    // resolve scope_key as (string) $project->id
}
```

Reuse `memory.show` view with `$scopeLabel = $project->title`.

- [ ] **Step 4: Feature tests**

Extend `WorkingMemoryWebTest` for project route authorization (other user → 403).

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/idea.blade.php resources/views/idea/index.blade.php resources/views/idea/partials/working_memory_home_strip.blade.php resources/views/projects/show.blade.php resources/views/projects/partials/working-memory-module.blade.php app/Http/Controllers/MemoryController.php routes/web.php tests/Feature/WorkingMemoryWebTest.php
git commit -m "feat(ui): nav, home strip, project-scoped memory route"
```

---

### Task 9: Documentation alignment

**Files:**
- Modify: `README.md` or in-app Help if there is a help route listing features — minimal: extend `docs/mcp-integration-guide.md` only if API JSON shape changed (document new fields).

Add to `README.md` under working memory section:

- `FEATURE_WORKING_MEMORY_UI`, `FEATURE_WORKING_MEMORY_INSIGHTS`, `WORKING_MEMORY_INSIGHTS_MODEL_ENABLED`
- Link to Settings path `/settings/working-memory`

- [ ] **Step 1: Commit**

```bash
git add README.md docs/mcp-integration-guide.md
git commit -m "docs: working memory UI flags and API payload fields"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task coverage |
|------------------|---------------|
| `/memory` global page | Task 6 |
| `/memory/insights` | Task 7 |
| Home strip + Memory nav (hybrid) | Task 8 |
| Project module / scoped memory | Task 8 (`/projects/{project}/memory`) |
| Phase 3 overlay (drawer) | Tasks 4 + 6 |
| Freshness minimal + Details | Task 6 blade |
| Env default + user override | Tasks 2–3 + 5 |
| Feature flags | Tasks 1 + 6–7 middleware |
| Insights corpus research-heavy | Task 7 |
| Tests | Each task |

**Placeholder scan:** none intentional; open implementation choices (exact drawer styling) follow existing Tailwind patterns in repo.

**Type consistency:** JSON keys listed match assembler output; routes named consistently.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-05-working-memory-ui-phase3-implementation.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**

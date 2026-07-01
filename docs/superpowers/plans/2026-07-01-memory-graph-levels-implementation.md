# Memory graph levels — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a shared graph stack (service + JSON + vis-network partial) and five feature-flagged graph levels (local, project, tag, semantic layer, vault), plus optional v2 link suggestions (background, promote-only).

**Architecture:** `ThoughtGraphService` assembles `{ nodes, edges, meta }` for all modes. Thin controllers authorize, parse query params, strip layers when flags are off, delegate. One parameterized middleware `memory.graph:{level}` gates routes. Semantic edges are on-demand k-NN only; v2 suggestions use `thought_suggested_links` + `ComputeSemanticLinkSuggestionsJob` (never auto-writes `thought_links`).

**Tech Stack:** Laravel 12, PHP 8.2+, Pest, Blade, Tailwind 4, pgvector (`Thought::nearestWithin`), vis-network 9.x (CDN, pin version).

**Spec:** [`docs/superpowers/specs/2026-07-01-memory-graph-levels-design.md`](../specs/2026-07-01-memory-graph-levels-design.md)

---

## File structure

| Responsibility | Files |
|----------------|--------|
| Config | `config/features.php`, `.env.example` |
| Middleware | `app/Http/Middleware/EnsureMemoryGraphFeatureEnabled.php`; `bootstrap/app.php` |
| Graph core | `app/Services/Graph/ThoughtGraphService.php`, `app/Services/Graph/ThoughtGraphQuery.php` (DTO for params), `app/Enums/ThoughtGraphMode.php` |
| HTTP | `app/Http/Controllers/ThoughtLocalGraphController.php`, `TagGraphController.php`, `VaultGraphController.php`; modify `ProjectGraphController.php` |
| Views | `resources/views/graph/partials/vis_network_canvas.blade.php`, `thought_local_graph_panel.blade.php`, `tag_constellation.blade.php`, `vault.blade.php`; modify `projects/graph.blade.php`, `idea/show.blade.php`, `projects/show.blade.php`, `layouts/idea.blade.php` |
| Routes | `routes/web.php` |
| Help | `resources/content/help/memory-graph.md`, `HelpController` method, route |
| Tests | `tests/Unit/Services/Graph/ThoughtGraphServiceTest.php`, `tests/Feature/MemoryGraphFeatureFlagsTest.php`, `tests/Feature/ThoughtLocalGraphTest.php`, `tests/Feature/ProjectGraphEnhancedTest.php`, `tests/Feature/TagGraphTest.php`, `tests/Feature/VaultGraphTest.php`, `tests/Feature/ThoughtLinkSuggestionTest.php` (phase 6) |
| v2 suggestions | `database/migrations/xxxx_create_thought_suggested_links_table.php`, `app/Models/ThoughtSuggestedLink.php`, `app/Jobs/ComputeSemanticLinkSuggestionsJob.php`, `ThoughtSuggestedLinkController.php`, observer hook in `ThoughtObserver.php` |

---

## Chunk 0: Feature flags, middleware, graph service skeleton

### Task 0.1: Register feature flags

**Files:**
- Modify: `config/features.php`
- Modify: `.env.example`
- Modify: `tests/Unit/Config/FeaturesConfigTest.php`

- [ ] **Step 1: Add failing test**

```php
#[Test]
public function memory_graph_feature_keys_exist_with_expected_defaults(): void
{
    $this->assertFalse(config('features.memory_graph_local'));
    $this->assertTrue(config('features.memory_graph_project'));
    $this->assertFalse(config('features.memory_graph_tag'));
    $this->assertFalse(config('features.memory_graph_semantic'));
    $this->assertFalse(config('features.memory_graph_vault'));
    $this->assertFalse(config('features.memory_graph_suggestions'));
}
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test tests/Unit/Config/FeaturesConfigTest.php --filter=memory_graph`

- [ ] **Step 3: Add config keys**

```php
// config/features.php (append)
'memory_graph_local' => env('FEATURE_MEMORY_GRAPH_LOCAL', false),
'memory_graph_project' => env('FEATURE_MEMORY_GRAPH_PROJECT', true),
'memory_graph_tag' => env('FEATURE_MEMORY_GRAPH_TAG', false),
'memory_graph_semantic' => env('FEATURE_MEMORY_GRAPH_SEMANTIC', false),
'memory_graph_vault' => env('FEATURE_MEMORY_GRAPH_VAULT', false),
'memory_graph_suggestions' => env('FEATURE_MEMORY_GRAPH_SUGGESTIONS', false),
```

Add matching block to `.env.example` per spec.

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit** `feat: add memory graph feature flags`

---

### Task 0.2: Parameterized graph middleware

**Files:**
- Create: `app/Http/Middleware/EnsureMemoryGraphFeatureEnabled.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Feature/MemoryGraphFeatureFlagsTest.php`

- [ ] **Step 1: Failing feature test** (temporary route in test or use project graph after wiring)

```php
public function test_project_graph_returns_404_when_feature_disabled(): void
{
    config(['features.memory_graph_project' => false]);
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('projects.graph', $project))
        ->assertNotFound();
}
```

- [ ] **Step 2: Implement middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemoryGraphFeatureEnabled
{
    private const LEVEL_CONFIG = [
        'local' => 'memory_graph_local',
        'project' => 'memory_graph_project',
        'tag' => 'memory_graph_tag',
        'semantic' => 'memory_graph_semantic',
        'vault' => 'memory_graph_vault',
        'suggestions' => 'memory_graph_suggestions',
    ];

    public function handle(Request $request, Closure $next, string $level): Response
    {
        $configKey = self::LEVEL_CONFIG[$level] ?? null;
        if ($configKey === null || ! config("features.{$configKey}")) {
            abort(404);
        }

        return $next($request);
    }
}
```

Register in `bootstrap/app.php`:

```php
'memory.graph' => \App\Http\Middleware\EnsureMemoryGraphFeatureEnabled::class,
```

- [ ] **Step 3: Wrap project graph routes**

```php
Route::middleware('memory.graph:project')->group(function () {
    Route::get('/projects/{project}/graph', [ProjectGraphController::class, 'show'])->name('projects.graph');
    Route::get('/projects/{project}/graph/data', [ProjectGraphController::class, 'data'])->name('projects.graph.data');
});
```

- [ ] **Step 4: Run tests** — existing `ProjectGraphAndShareManagementTest` still passes with default flag true; new test passes when false.

- [ ] **Step 5: Commit** `feat: gate project graph behind memory_graph_project flag`

---

### Task 0.3: `ThoughtGraphQuery` + `ThoughtGraphMode`

**Files:**
- Create: `app/Enums/ThoughtGraphMode.php`
- Create: `app/Services/Graph/ThoughtGraphQuery.php`

- [ ] **Step 1: Create enum**

```php
<?php

namespace App\Enums;

enum ThoughtGraphMode: string
{
    case Local = 'local';
    case Project = 'project';
    case Tag = 'tag';
    case Semantic = 'semantic';
    case Vault = 'vault';
}
```

- [ ] **Step 2: Create query DTO** (readonly class with factory from Request helpers)

```php
<?php

namespace App\Services\Graph;

use App\Enums\ThoughtGraphMode;

final class ThoughtGraphQuery
{
    public function __construct(
        public ThoughtGraphMode $mode,
        public int $userId,
        public ?string $focalThoughtId = null,
        public ?string $projectId = null,
        public ?string $tag = null,
        public int $depth = 1,
        public bool $includeParentChild = true,
        public bool $includeChunks = false,
        public bool $includeNeighbors = false,
        public bool $includeSemantic = false,
        public int $semanticK = 8,
        public float $maxDistance = 0.45,
        public array $linkTypes = [],
        public array $layers = ['thought_link'],
        public ?string $source = null,
        public ?string $since = null,
        public ?string $until = null,
        public int $limit = 200,
    ) {}

    public static function forLocal(int $userId, string $focalId, array $input): self { /* map query params */ }
    public static function forProject(int $userId, string $projectId, array $input): self { /* ... */ }
    // forTag, forSemantic, forVault similars
}
```

- [ ] **Step 3: Commit** `feat: add ThoughtGraphMode and ThoughtGraphQuery DTO`

---

### Task 0.4: `ThoughtGraphService` — curated + structural edges

**Files:**
- Create: `app/Services/Graph/ThoughtGraphService.php`
- Create: `tests/Unit/Services/Graph/ThoughtGraphServiceTest.php`

- [ ] **Step 1: Unit test — BFS local neighborhood**

```php
public function test_local_graph_collects_focal_and_one_hop_links(): void
{
    $user = User::factory()->create();
    $focal = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Focal']);
    $linked = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Linked']);
    ThoughtLink::factory()->create([
        'user_id' => $user->id,
        'from_thought_id' => $focal->id,
        'to_thought_id' => $linked->id,
        'link_type' => ThoughtLinkType::RelatesTo->value,
    ]);

    $service = app(ThoughtGraphService::class);
    $query = ThoughtGraphQuery::forLocal($user->id, $focal->id, ['depth' => 1]);
    $payload = $service->build($query);

    $this->assertSame('local', $payload['meta']['mode']);
    $this->assertCount(2, $payload['nodes']);
    $this->assertCount(1, $payload['edges']);
    $this->assertSame('thought_link', $payload['edges'][0]['edge_type']);
}
```

- [ ] **Step 2: Implement service skeleton**

Public method:

```php
public function build(ThoughtGraphQuery $query): array
{
    return match ($query->mode) {
        ThoughtGraphMode::Local => $this->buildLocal($query),
        ThoughtGraphMode::Project => $this->buildProject($query),
        ThoughtGraphMode::Tag => $this->buildTag($query),
        ThoughtGraphMode::Semantic => $this->buildSemantic($query),
        ThoughtGraphMode::Vault => $this->buildVault($query),
    };
}
```

Implement in this task:
- `collectThoughtLinkNeighborhood(string $focalId, int $userId, int $depth): Collection`
- `appendParentChildNodes(Collection $thoughts, Thought $focal, bool $includeChunks): void`
- `curatedEdges(Collection $thoughtIds, int $userId, array $linkTypes = []): array`
- `structuralEdges(Collection $thoughts): array`
- `nodePayload(Thought $thought, string $group): array` — label via `Str::limit($t->content, 48)`, `url` => `route('thoughts.show', $t)`
- `assemble(array $nodes, array $edges, array $meta): array` — dedupe edges by `id`, enforce caps

Strip chunks when `!$query->includeChunks` unless focal has `parent_id`.

- [ ] **Step 3: Run unit tests**

Run: `php artisan test tests/Unit/Services/Graph/ThoughtGraphServiceTest.php`

- [ ] **Step 4: Commit** `feat: ThoughtGraphService curated and structural edges`

---

### Task 0.5: Shared vis-network partial

**Files:**
- Create: `resources/views/graph/partials/vis_network_canvas.blade.php`
- Extract JS from `resources/views/projects/graph.blade.php`

- [ ] **Step 1: Create partial** accepting:

```blade
@props([
    'dataUrl',
    'canvasId' => 'graph-canvas',
    'emptyMessage' => 'No connections to display.',
    'height' => 'min(72vh, 900px)',
    'minHeight' => '420px',
])
```

Move stabilization + `fit()` + resize + double-click logic from `projects/graph.blade.php`. Pin CDN:

```html
<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
```

Map `group: focal` → highlight border `#6D6AF7`; `edge.dashed` from JSON.

- [ ] **Step 2: Refactor `projects/graph.blade.php`** to use partial (behaviour unchanged).

- [ ] **Step 3: Manual smoke** — open project graph with 2+ linked thoughts.

- [ ] **Step 4: Commit** `refactor: shared vis-network graph partial`

---

## Chunk 1: Level 1 — Local graph on thought detail

### Task 1.1: Local graph controller + routes

**Files:**
- Create: `app/Http/Controllers/ThoughtLocalGraphController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ThoughtLocalGraphTest.php`

- [ ] **Step 1: Failing feature test**

```php
public function test_local_graph_data_returns_focal_and_linked_nodes_when_flag_on(): void
{
    config(['features.memory_graph_local' => true]);
    // seed focal + link like unit test
    $response = $this->actingAs($user)->getJson(route('thoughts.graph.data', $focal));
    $response->assertOk()->assertJsonPath('meta.mode', 'local');
}

public function test_local_graph_404_when_flag_off(): void
{
    config(['features.memory_graph_local' => false]);
    $this->actingAs($user)->getJson(route('thoughts.graph.data', $focal))->assertNotFound();
}
```

- [ ] **Step 2: Controller**

```php
class ThoughtLocalGraphController extends Controller
{
    public function __construct(private ThoughtGraphService $graphs) {}

    public function show(Thought $thought): View
    {
        $this->authorize('view', $thought);
        return view('graph.thought_local', ['thought' => $thought]);
    }

    public function data(Request $request, Thought $thought): JsonResponse
    {
        $this->authorize('view', $thought);
        $query = ThoughtGraphQuery::forLocal((int) $request->user()->id, $thought->id, $request->query());
        $query = $this->stripDisabledLayers($request, $query);
        return response()->json($this->graphs->build($query));
    }

    private function stripDisabledLayers(Request $request, ThoughtGraphQuery $query): ThoughtGraphQuery
    {
        if (! config('features.memory_graph_semantic')) {
            $query->includeSemantic = false;
        }
        return $query;
    }
}
```

Routes inside `auth` + `memory.graph:local`:

```php
Route::middleware('memory.graph:local')->group(function () {
    Route::get('/thoughts/{thought}/graph', [ThoughtLocalGraphController::class, 'show'])->name('thoughts.graph');
    Route::get('/thoughts/{thought}/graph/data', [ThoughtLocalGraphController::class, 'data'])->name('thoughts.graph.data');
});
```

- [ ] **Step 3: Full-page view** `resources/views/graph/thought_local.blade.php` — header + partial, height full viewport.

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit** `feat: local thought graph API and page`

---

### Task 1.2: Thought detail panel

**Files:**
- Create: `resources/views/graph/partials/thought_local_graph_panel.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_projects_and_links.blade.php` (or `show.blade.php` footer slot)

- [ ] **Step 1: Panel** — `<details>` closed by default; includes vis partial with `dataUrl` = `route('thoughts.graph.data', $thought)` + query string builder for `include_semantic` toggle (only if both local + semantic flags on).

- [ ] **Step 2: Include only when** `config('features.memory_graph_local')`.

- [ ] **Step 3: Feature test** — thought show HTML contains `Connection graph` when flag on, absent when off.

```php
public function test_thought_detail_shows_connection_graph_panel_when_flag_on(): void
{
    config(['features.memory_graph_local' => true]);
    $this->actingAs($user)->get(route('thoughts.show', $thought))->assertSee('Connection graph');
}
```

- [ ] **Step 4: Commit** `feat: connection graph panel on thought detail`

---

## Chunk 2: Level 2 — Enhanced project graph

### Task 2.1: Delegate `ProjectGraphController` to service

**Files:**
- Modify: `app/Http/Controllers/ProjectGraphController.php`
- Create: `tests/Feature/ProjectGraphEnhancedTest.php`

- [ ] **Step 1: Test neighbor toggle**

```php
public function test_include_neighbors_adds_linked_outside_member(): void
{
    config(['features.memory_graph_project' => true]);
    // member A in project, outside B linked to A, not in project
    $url = route('projects.graph.data', $project).'?include_neighbors=1';
    $response = $this->actingAs($user)->getJson($url);
    $response->assertOk()->assertJsonCount(2, 'nodes'); // A + neighbor B
}
```

- [ ] **Step 2: Implement `buildProject()`** in `ThoughtGraphService`:
  - Seed nodes from `project->thoughts()`
  - `curatedEdges` among members; filter `link_types`
  - If `include_neighbors`: query `ThoughtLink` touching any member ID, add outsider nodes (cap 50, `group: neighbor`)
  - If `include_parent_child`: structural edges among members
  - Return `meta.mode = project`

- [ ] **Step 3: Refactor controller `data()`** to use service; preserve backward-compatible JSON keys (`nodes`, `edges`) plus `meta`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/ProjectGraphAndShareManagementTest.php tests/Feature/ProjectGraphEnhancedTest.php`

- [ ] **Step 5: Commit** `feat: project graph service with neighbor filter`

---

### Task 2.2: Project graph toolbar UI

**Files:**
- Modify: `resources/views/projects/graph.blade.php`
- Modify: `resources/views/projects/show.blade.php` — hide Graph button when `!config('features.memory_graph_project')`

- [ ] **Step 1: Toolbar** — checkboxes for `ThoughtLinkType` cases; toggles Neighbors, Sections; Similar toggle wrapped in `@if(config('features.memory_graph_semantic'))`.

- [ ] **Step 2: JS** — rebuild `dataUrl` on change, refetch, update vis DataSets.

- [ ] **Step 3: Commit** `feat: project graph filter toolbar`

---

## Chunk 3: Level 4 — Semantic layer (on-demand)

### Task 3.1: `SemanticEdgeBuilder` logic in service

**Files:**
- Modify: `app/Services/Graph/ThoughtGraphService.php`
- Extend: `tests/Unit/Services/Graph/ThoughtGraphServiceTest.php`

- [ ] **Step 1: Test semantic edges for focal**

```php
public function test_semantic_mode_adds_edges_to_nearest_neighbors(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgvector required');
    }
    $embedding = array_fill(0, 1536, 0.0);
    $embedding[0] = 1.0;
    $focal = Thought::factory()->create(['user_id' => $user->id, 'embedding' => $embedding]);
    $near = Thought::factory()->create(['user_id' => $user->id, 'embedding' => $embedding]);
    $far = Thought::factory()->create(['user_id' => $user->id, 'embedding' => array_fill(0, 1536, 0.99)]);

    $query = new ThoughtGraphQuery(mode: ThoughtGraphMode::Semantic, userId: $user->id, focalThoughtId: $focal->id, includeSemantic: true, semanticK: 5);
    $payload = app(ThoughtGraphService::class)->build($query);

    $this->assertTrue(collect($payload['edges'])->contains(fn ($e) => $e['edge_type'] === 'semantic'));
}
```

- [ ] **Step 2: Implement `buildSemantic()`** and private `semanticEdgesForThoughts(Collection $thoughts, ...)` using `Thought::nearestWithin`.

- [ ] **Step 3: Wire into `buildLocal()` and `buildProject()`** when `$query->includeSemantic && config('features.memory_graph_semantic')`.

- [ ] **Step 4: Commit** `feat: on-demand semantic graph edges`

---

### Task 3.2: Dedicated semantic-graph routes (optional full page)

**Files:**
- Create: `app/Http/Controllers/ThoughtSemanticGraphController.php`
- Modify: `routes/web.php`
- Extend: `tests/Feature/ThoughtLocalGraphTest.php`

- [ ] **Step 1: Routes** behind `memory.graph:semantic`

- [ ] **Step 2: Controller** builds `ThoughtGraphMode::Semantic` query

- [ ] **Step 3: Test** `meta.error = no_embedding` when focal lacks embedding

- [ ] **Step 4: Commit** `feat: semantic neighborhood graph routes`

---

## Chunk 4: Level 3 — Tag constellation

### Task 4.1: Tag graph controller + service

**Files:**
- Create: `app/Http/Controllers/TagGraphController.php`
- Create: `resources/views/graph/tag_constellation.blade.php`
- Create: `tests/Feature/TagGraphTest.php`

- [ ] **Step 1: Test tag hub nodes**

```php
public function test_tag_graph_returns_thoughts_with_tag_and_hub_edge(): void
{
    config(['features.memory_graph_tag' => true]);
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['tags' => ['decision:alpha']],
    ]);
    $response = $this->actingAs($user)->getJson(route('graph.tags.data', ['tag' => 'decision:alpha']));
    $response->assertOk();
    $ids = collect($response->json('nodes'))->pluck('id');
    $this->assertTrue($ids->contains($thought->id));
    $this->assertTrue($ids->contains('tag:decision_alpha'));
}
```

- [ ] **Step 2: Implement `buildTag()`** — tag match via existing `scopeTagMatchesQuery` or JSON tag array; synthetic hub node `id: tag:{slug}`, `group: hub`, non-URL; star edges `shared_tag`.

- [ ] **Step 3: Routes** `graph.tags`, `graph.tags.data` with `memory.graph:tag`

- [ ] **Step 4: Commit** `feat: tag constellation graph`

---

### Task 4.2: Stream + tag chip entry points

**Files:**
- Modify: stream blade partial (tag filter header — locate in `IdeaController` stream view)
- Modify: `resources/views/idea/partials/thought_tag_row.blade.php` — optional “graph” icon link when flag on

- [ ] **Step 1: Add link** `route('graph.tags', ['tag' => $tag])` beside stream filter when `memory_graph_tag`.

- [ ] **Step 2: Feature test** stream with tag param includes “View as graph” when flag on.

- [ ] **Step 3: Commit** `feat: tag graph entry points from stream and tags`

---

## Chunk 5: Level 5 — Vault graph

### Task 5.1: Vault controller + service

**Files:**
- Create: `app/Http/Controllers/VaultGraphController.php`
- Create: `resources/views/graph/vault.blade.php`
- Create: `tests/Feature/VaultGraphTest.php`

- [ ] **Step 1: Test caps and truncation**

```php
public function test_vault_graph_truncates_at_limit(): void
{
    config(['features.memory_graph_vault' => true]);
    Thought::factory()->count(5)->create(['user_id' => $user->id]);
    $response = $this->actingAs($user)->getJson(route('graph.vault.data', ['limit' => 3]));
    $response->assertOk()->assertJsonPath('meta.truncated', true)->assertJsonCount(3, 'nodes');
}
```

- [ ] **Step 2: Implement `buildVault()`** per spec assembly algorithm; `visibleInStream()` on seed query; layer gating with `meta.warnings`.

- [ ] **Step 3: Routes** `/graph`, `/graph/data` with `memory.graph:vault`

- [ ] **Step 4: Nav link** in `layouts/idea.blade.php` when flag on (mirror Pulse pattern).

- [ ] **Step 5: Commit** `feat: vault memory graph with filters and caps`

---

## Chunk 6: Help + demo mode

### Task 6.1: Help page

**Files:**
- Create: `resources/content/help/memory-graph.md`
- Modify: `app/Http/Controllers/HelpController.php`, `routes/web.php`

- [ ] **Step 1: Document** all `FEATURE_MEMORY_GRAPH_*` flags and routes.

- [ ] **Step 2: Route** `GET /help/memory-graph` (always available, no feature gate).

- [ ] **Step 3: Commit** `docs: memory graph help page`

---

### Task 6.2: Demo mode obfuscation

**Files:**
- Modify: `ThoughtGraphService::nodePayload()` or controller after build

- [ ] **Step 1: When `DemoMode::enabled()`**, map labels through `DemoObfuscator` (same as link target picker).

- [ ] **Step 2: Test** in existing demo mode feature test suite.

- [ ] **Step 3: Commit** `feat: obfuscate graph node labels in demo mode`

---

## Chunk 7 (optional v2): Link suggestions — background, promote-only

### Task 7.1: Migration + model

**Files:**
- Create: `database/migrations/2026_07_01_100000_create_thought_suggested_links_table.php`
- Create: `app/Models/ThoughtSuggestedLink.php`

- [ ] **Step 1: Migration** per spec columns + unique `(from_thought_id, to_thought_id)`

- [ ] **Step 2: Model** with `fromThought`, `toThought`, scopes `active()` (not dismissed/promoted)

- [ ] **Step 3: Commit** `feat: thought_suggested_links table`

---

### Task 7.2: `ComputeSemanticLinkSuggestionsJob`

**Files:**
- Create: `app/Jobs/ComputeSemanticLinkSuggestionsJob.php`
- Modify: `app/Observers/ThoughtObserver.php`
- Create: `tests/Feature/ThoughtLinkSuggestionTest.php`

- [ ] **Step 1: Test job creates suggestions, not thought_links**

- [ ] **Step 2: Job** — top 5 `nearestWithin`, skip existing links, upsert non-dismissed rows

- [ ] **Step 3: Observer** dispatch when `content` changed AND `config('features.memory_graph_suggestions')`

- [ ] **Step 4: Commit** `feat: compute semantic link suggestions job`

---

### Task 7.3: Suggestions UI + promote/dismiss

**Files:**
- Create: `app/Http/Controllers/ThoughtSuggestedLinkController.php`
- Create: `resources/views/idea/partials/thought_suggested_links.blade.php`
- Modify: thought detail includes

- [ ] **Step 1: Routes** `POST /thoughts/{thought}/suggestions/{suggestion}/dismiss`, promote reuses `ThoughtLinkController::store` with prefill

- [ ] **Step 2: Panel** when `memory_graph_suggestions` flag on

- [ ] **Step 3: Tests** dismiss persists; promote sets `promoted_at` + creates link

- [ ] **Step 4: Commit** `feat: suggested links UI with promote and dismiss`

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Feature flags per level | 0.1, 0.2 |
| Middleware 404 | 0.2 |
| Shared JSON contract + meta | 0.4 |
| vis-network partial | 0.5 |
| L1 local graph | 1.1, 1.2 |
| L2 project enhancements | 2.1, 2.2 |
| L4 semantic on-demand | 3.1, 3.2 |
| L3 tag constellation | 4.1, 4.2 |
| L5 vault graph | 5.1 |
| Help page | 6.1 |
| Demo obfuscation | 6.2 |
| Suggestions-only background | 7.1–7.3 |
| Strip semantic when flag off | 1.1, 3.1 |
| No auto thought_links | 7.2 (assert in test) |

---

## Verification commands

After each chunk:

```bash
php artisan test tests/Unit/Services/Graph/
php artisan test tests/Feature/MemoryGraphFeatureFlagsTest.php
php artisan test tests/Feature/ThoughtLocalGraphTest.php
php artisan test tests/Feature/ProjectGraphAndShareManagementTest.php
php artisan test tests/Feature/ProjectGraphEnhancedTest.php
```

Full suite before merge:

```bash
php artisan test
vendor/bin/pint --dirty
```

---

## Execution order summary

1. **Chunk 0** — flags, middleware, service skeleton, shared partial (required for everything)
2. **Chunk 1** — local graph (highest value)
3. **Chunk 2** — project graph enhancements
4. **Chunk 3** — semantic layer
5. **Chunk 4** — tag constellation
6. **Chunk 5** — vault graph
7. **Chunk 6** — help + demo
8. **Chunk 7** — suggestions (optional, independent)

Enable flags in `.env` as each chunk lands.

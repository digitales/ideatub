# Working Memory (Global + Project) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a native, versioned working-memory system in IdeaTub that serves global and project-scoped memory with hybrid refresh (incremental + scheduled consolidation) and source traceability.

**Architecture:** Introduce three new persistence tables (`working_memories`, `working_memory_versions`, `working_memory_inputs`) plus a service layer for scope resolution, synthesis, and read assembly. Trigger incremental refresh jobs on thought writes and run scheduled consolidation nightly. Expose memory via both MCP (`get_working_memory`) and REST (`GET /api/thoughts/working-memory`) with freshness metadata.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL/SQLite test support, existing `Thought`/`Project` models, existing MCP controller pattern, Pest/PHPUnit feature + unit tests.

---

## Scope check

The approved spec is one cohesive subsystem (working memory), not multiple independent products. This plan keeps one implementation track with phased delivery:

1. persistence + build services,
2. incremental + scheduled refresh,
3. API/MCP read surfaces.

### Product decisions (locked)

- **Consolidation window:** default **180 days** (`config/working_memory.php`, override via `WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS`). Applies to **consolidated** builds.
- **Archived / fringe content:** **no** dedicated archived handling in v1; recency window provides fade-out.
- **UI:** ship **both** a dedicated memory page and a project-page module when frontend work is scheduled (API already shared).
- **Confidence:** v1 keeps **heuristic** scoring; **model-assisted** refinement is optional behind config/feature flag when implemented.

---

## File structure (creates + touches)

| Path | Responsibility |
|------|----------------|
| `database/migrations/2026_05_05_120000_create_working_memory_tables.php` | Create `working_memories`, `working_memory_versions`, `working_memory_inputs`. |
| `app/Models/WorkingMemory.php` | Scope-level memory record (`global` or `project`). |
| `app/Models/WorkingMemoryVersion.php` | Immutable snapshot payload + confidence/freshness context. |
| `app/Models/WorkingMemoryInput.php` | Trace links from version to source thoughts. |
| `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php` | Resolve affected scopes from thought + metadata/project links. |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Build canonical output shape from versions + deltas. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Build incremental/consolidated snapshots; persist inputs and confidence. |
| `config/working_memory.php` | Consolidation window days and related tuning (defaults aligned with design). |
| `app/Jobs/RefreshWorkingMemoryIncremental.php` | Event-triggered incremental updates per affected scope. |
| `app/Jobs/ConsolidateWorkingMemory.php` | Full periodic rebuild per scope. |
| `app/Console/Commands/WorkingMemoryConsolidateCommand.php` | Manual/scheduled consolidation entrypoint. |
| `routes/console.php` | Nightly scheduler registration for consolidation command. |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Add REST endpoint for working memory retrieval. |
| `routes/api.php` | Register `GET /api/thoughts/working-memory`. |
| `app/Http/Controllers/Api/McpController.php` | Add `get_working_memory` MCP method + `tools/list` schema + dispatch. |
| `app/Observers/ThoughtObserver.php` and `app/Providers/AppServiceProvider.php` | Dispatch incremental refresh when thought is created/updated with meaningful changes. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php` | Scope mapping correctness (global + project). |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | Snapshot build logic, shape, and confidence handling. |
| `tests/Feature/WorkingMemoryApiTest.php` | REST endpoint behavior and auth boundaries. |
| `tests/Feature/McpApiTest.php` | MCP method listing + call behavior for `get_working_memory`. |
| `tests/Feature/WorkingMemoryConsolidationCommandTest.php` | Consolidation command and scheduler behavior. |

---

### Task 1: Create working-memory persistence layer

**Files:**
- Create: `database/migrations/2026_05_05_120000_create_working_memory_tables.php`
- Create: `app/Models/WorkingMemory.php`
- Create: `app/Models/WorkingMemoryVersion.php`
- Create: `app/Models/WorkingMemoryInput.php`

- [ ] **Step 1: Write failing migration smoke tests**

```php
<?php

use Illuminate\Support\Facades\Schema;

it('creates working memory tables', function () {
    expect(Schema::hasTable('working_memories'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_versions'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_inputs'))->toBeTrue();
});

it('enforces critical uniqueness constraints', function () {
    // assert unique(user_id, scope_type, scope_key) and unique(version_id, thought_id)
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WorkingMemorySchemaTest.php -v`  
Expected: FAIL with missing table assertions.

- [ ] **Step 3: Add migration with indexes/constraints**

```php
Schema::create('working_memories', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('scope_type', 32); // global|project
    $table->string('scope_key', 191); // global or metadata/project identifier
    $table->foreignUuid('latest_version_id')->nullable();
    $table->string('freshness_state', 32)->default('stale'); // fresh|degraded|stale
    $table->timestamp('last_refreshed_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'scope_type', 'scope_key'], 'working_memories_scope_unique');
    $table->index(['user_id', 'scope_type']);
    $table->index('latest_version_id', 'working_memories_latest_version_idx');
});
```

```php
Schema::create('working_memory_versions', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('working_memory_id')->constrained('working_memories')->cascadeOnDelete();
    $table->string('build_type', 32); // incremental|consolidated
    $table->longText('summary_markdown');
    $table->json('key_concepts_json')->nullable();
    $table->json('active_threads_json')->nullable();
    $table->json('open_questions_json')->nullable();
    $table->json('next_actions_json')->nullable();
    $table->decimal('confidence_score', 5, 2)->default(0);
    $table->timestamp('source_window_start')->nullable();
    $table->timestamp('source_window_end')->nullable();
    $table->timestamps();

    $table->index(['working_memory_id', 'build_type']);
    $table->index(['working_memory_id', 'created_at']);
});
```

```php
Schema::table('working_memories', function (Blueprint $table): void {
    $table->foreign('latest_version_id', 'working_memories_latest_version_fk')
        ->references('id')
        ->on('working_memory_versions')
        ->nullOnDelete();
});
```

- [ ] **Step 4: Add model relationships/casts**

```php
class WorkingMemory extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id', 'scope_type', 'scope_key', 'latest_version_id',
        'freshness_state', 'last_refreshed_at',
    ];

    protected function casts(): array
    {
        return ['last_refreshed_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkingMemoryVersion::class);
    }
}
```

- [ ] **Step 5: Re-run tests and migration**

Run: `php artisan test tests/Feature/WorkingMemorySchemaTest.php -v`  
Expected: PASS  

Run: `php artisan migrate`  
Expected: migration applies cleanly.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_05_120000_create_working_memory_tables.php app/Models/WorkingMemory.php app/Models/WorkingMemoryVersion.php app/Models/WorkingMemoryInput.php tests/Feature/WorkingMemorySchemaTest.php
git commit -m "feat(memory): add working memory persistence tables and models"
```

---

### Task 2: Implement scope resolution (global + metadata/project)

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php`

- [ ] **Step 1: Write failing resolver tests**

```php
it('always includes global scope', function () {
    $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);
    expect($scopes)->toContain(['scope_type' => 'global', 'scope_key' => 'global']);
});

it('includes project scopes from source metadata and linked projects', function () {
    $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thoughtWithProjectContext);
    expect($scopes)->toContain(['scope_type' => 'project', 'scope_key' => 'my-app']);
});
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php -v`  
Expected: FAIL with missing class/method.

- [ ] **Step 3: Implement resolver with deterministic normalization**

```php
public function forThought(Thought $thought): array
{
    $scopes = [['scope_type' => 'global', 'scope_key' => 'global']];

    $metaProject = data_get($thought->source_metadata, 'project');
    if (is_string($metaProject) && trim($metaProject) !== '') {
        $scopes[] = ['scope_type' => 'project', 'scope_key' => Str::of($metaProject)->trim()->lower()->toString()];
    }

    foreach ($thought->projects()->pluck('projects.id') as $projectId) {
        $scopes[] = ['scope_type' => 'project', 'scope_key' => (string) $projectId];
    }

    return collect($scopes)->unique(fn ($s) => $s['scope_type'].'|'.$s['scope_key'])->values()->all();
}
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryScopeResolver.php tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php
git commit -m "feat(memory): add scope resolver for global and project contexts"
```

---

### Task 3: Build and persist memory versions (incremental + consolidated)

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Create: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

- [ ] **Step 1: Write failing builder tests**

```php
it('creates a consolidated version with required sections', function () {
    $version = app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');
    expect($version->build_type)->toBe('consolidated')
        ->and($version->summary_markdown)->toContain('## Key concepts');
});

it('persists source thought links for traceability', function () {
    $version = app(WorkingMemoryBuilderService::class)->buildIncremental($user->id, 'project', 'my-app');
    expect($version->inputs()->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run tests and confirm failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php -v`  
Expected: FAIL with missing class/method.

- [ ] **Step 3: Implement builder using deterministic section schema**

```php
private function renderSummary(array $payload): string
{
    return implode("\n\n", [
        '## Executive summary',
        $payload['executive_summary'],
        '## Key concepts',
        collect($payload['key_concepts'])->map(fn ($row) => '- '.$row['title'])->implode("\n"),
        '## Active threads',
        collect($payload['active_threads'])->map(fn ($row) => '- '.$row['title'])->implode("\n"),
        '## Open questions',
        collect($payload['open_questions'])->map(fn ($row) => '- '.$row['question'])->implode("\n"),
        '## Next actions',
        collect($payload['next_actions'])->map(fn ($row) => '- '.$row['action'])->implode("\n"),
    ]);
}
```

Use a first-pass heuristic summary (top tags + recent clusters) to avoid blocking on new LLM dependencies. Keep `confidence_score` bounded `0..100`.

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php
git commit -m "feat(memory): add snapshot builder and assembler services"
```

---

### Task 4: Wire incremental and consolidation jobs

**Files:**
- Create: `app/Jobs/RefreshWorkingMemoryIncremental.php`
- Create: `app/Jobs/ConsolidateWorkingMemory.php`
- Create: `app/Observers/ThoughtObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Console/Commands/WorkingMemoryConsolidateCommand.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/WorkingMemoryConsolidationCommandTest.php`

- [ ] **Step 1: Write failing job/command tests**

```php
it('dispatches incremental refresh after thought creation', function () {
    Queue::fake();
    Thought::factory()->create([...]);
    Queue::assertPushed(RefreshWorkingMemoryIncremental::class);
});

it('consolidation command rebuilds all scopes for user', function () {
    $this->artisan('working-memory:consolidate --user=1')->assertExitCode(0);
});
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Feature/WorkingMemoryConsolidationCommandTest.php -v`  
Expected: FAIL (missing observer/command/job).

- [ ] **Step 3: Implement jobs + command + scheduler**

```php
// routes/console.php
Schedule::command('working-memory:consolidate')->dailyAt('02:45');
```

```php
// app/Observers/ThoughtObserver.php
public function created(Thought $thought): void
{
    RefreshWorkingMemoryIncremental::dispatch($thought->id);
}
```

Command signature:

```php
protected $signature = 'working-memory:consolidate {--user=} {--scope_type=} {--scope_key=}';
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Feature/WorkingMemoryConsolidationCommandTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RefreshWorkingMemoryIncremental.php app/Jobs/ConsolidateWorkingMemory.php app/Observers/ThoughtObserver.php app/Providers/AppServiceProvider.php app/Console/Commands/WorkingMemoryConsolidateCommand.php routes/console.php tests/Feature/WorkingMemoryConsolidationCommandTest.php
git commit -m "feat(memory): add incremental and scheduled consolidation jobs"
```

---

### Task 5: Add REST API read surface

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/WorkingMemoryApiTest.php`

- [ ] **Step 1: Write failing API feature tests**

```php
it('returns global working memory for authenticated oauth client', function () {
    $response = $this->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=global', $headers);
    $response->assertOk()->assertJsonStructure([
        'scope_type', 'scope_key', 'freshness_state', 'confidence_score',
        'summary_markdown', 'key_concepts', 'active_threads', 'open_questions', 'next_actions',
    ]);
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php -v`  
Expected: FAIL with 404 route missing.

- [ ] **Step 3: Implement controller action and route**

```php
Route::middleware('auth.oauth.bearer')->prefix('thoughts')->group(function (): void {
    // existing routes...
    Route::get('/working-memory', [ThoughtsApiController::class, 'workingMemory']);
});
```

```php
public function workingMemory(Request $request): JsonResponse
{
    $v = Validator::make($request->all(), [
        'scope_type' => 'required|string|in:global,project',
        'scope_key' => 'required|string|max:191',
    ]);
    if ($v->fails()) {
        return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
    }

    $payload = $this->workingMemoryAssembler->forScope((int) auth()->id(), ...$v->validated());
    return response()->json($payload);
}
```

- [ ] **Step 4: Re-run test**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/ThoughtsApiController.php routes/api.php tests/Feature/WorkingMemoryApiTest.php
git commit -m "feat(memory): expose working memory via oauth thoughts api"
```

---

### Task 6: Add MCP tool support (`get_working_memory`)

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `tests/Feature/McpApiTest.php`
- Modify: `docs/mcp-integration-guide.md`

- [ ] **Step 1: Write failing MCP tests**

```php
public function test_get_mcp_returns_get_working_memory_method(): void
{
    $response = $this->getJson('/api/mcp');
    $response->assertJsonPath('methods.13', 'get_working_memory');
}

public function test_get_working_memory_returns_scoped_payload(): void
{
    $response = $this->mcpPost($key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'get_working_memory',
        'params' => ['scope_type' => 'global', 'scope_key' => 'global'],
    ]);
    $response->assertStatus(200)->assertJsonStructure(['result' => ['summary_markdown', 'freshness_state']]);
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=working_memory -v`  
Expected: FAIL with method not found.

- [ ] **Step 3: Implement MCP method and schema**

```php
private function mcpMethodNames(): array
{
    return [
        // existing methods...
        'get_working_memory',
    ];
}
```

```php
private function dispatch(string $method, array $params): array
{
    return match ($method) {
        // existing...
        'get_working_memory' => $this->getWorkingMemory($params),
        default => throw new \InvalidArgumentException("Unknown method: {$method}"),
    };
}
```

Add `tools/list` entry:

```php
[
  'name' => 'get_working_memory',
  'description' => 'Return global or project working memory snapshot with freshness and confidence.',
  'inputSchema' => [
    'type' => 'object',
    'properties' => [
      'scope_type' => ['type' => 'string', 'enum' => ['global','project']],
      'scope_key' => ['type' => 'string'],
    ],
    'required' => ['scope_type', 'scope_key'],
  ],
]
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=working_memory -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php docs/mcp-integration-guide.md
git commit -m "feat(memory): add get_working_memory MCP tool"
```

---

### Task 7: Freshness states, failure fallback, and confidence policy

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php`

- [ ] **Step 1: Write failing tests for degraded/stale behavior**

```php
it('returns last known good version when latest build fails', function () {
    // seed one successful version, simulate next build exception
    // assert assembler returns successful snapshot and freshness_state=degraded
});

it('marks memory stale when no refresh happened within threshold', function () {
    // set last_refreshed_at far in the past
    // assert freshness_state=stale
});
```

- [ ] **Step 2: Run tests and verify failure**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php -v`  
Expected: FAIL.

- [ ] **Step 3: Implement freshness transitions**

```php
private function freshnessState(?Carbon $lastRefreshedAt): string
{
    if ($lastRefreshedAt === null) {
        return 'stale';
    }
    if ($lastRefreshedAt->lt(now()->subHours(24))) {
        return 'stale';
    }
    if ($lastRefreshedAt->lt(now()->subHours(4))) {
        return 'degraded';
    }
    return 'fresh';
}
```

- [ ] **Step 4: Re-run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php
git commit -m "feat(memory): add freshness state and last-known-good fallback"
```

---

### Task 8: Full verification and docs alignment

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-05-05-working-memory-design.md` (status/implementation notes only if needed)

- [ ] **Step 1: Add usage docs for API and MCP**

Document:
- MCP method: `get_working_memory`
- REST endpoint: `GET /api/thoughts/working-memory`
- Scope examples (`global/global`, `project/my-app`).

- [ ] **Step 2: Run focused test suite**

Run:

```bash
php artisan test tests/Unit/Services/WorkingMemory tests/Feature/WorkingMemoryApiTest.php tests/Feature/WorkingMemoryConsolidationCommandTest.php tests/Feature/McpApiTest.php --filter=working_memory
```

Expected: PASS.

- [ ] **Step 3: Run full suite + formatter**

Run: `php artisan test`  
Expected: PASS  

Run: `./vendor/bin/pint --dirty`  
Expected: no or minimal formatting changes.

- [ ] **Step 4: Commit docs/formatting changes**

```bash
git add README.md CLAUDE.md docs/superpowers/specs/2026-05-05-working-memory-design.md
git add -A
git commit -m "docs(memory): document working memory API and MCP usage"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task coverage |
|---|---|
| Native in-app working memory | Tasks 1, 3, 5, 6 |
| Global + project scopes | Tasks 2, 3, 5, 6 |
| Hybrid refresh (event + scheduled) | Task 4 |
| Versioned snapshots | Tasks 1, 3 |
| Traceability to source thoughts | Task 3 |
| Freshness and confidence indicators | Task 7 |
| Error fallback to last known good | Task 7 |
| API + MCP retrieval in one call | Tasks 5, 6 |
| Project evolution path (metadata-first) | Task 2, Task 8 docs notes |
| Testing strategy | Tasks 1–8 |

Placeholder scan: no TODO/TBD placeholders.  
Type consistency check: standardized on `scope_type` (`global|project`) + `scope_key` across DB, services, API, and MCP.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-05-working-memory-implementation.md`. Two execution options:**

1. **Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. **Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**

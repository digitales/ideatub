# Working Memory Hybrid (External-First + Version History) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Protect curated external working memory from legacy refresh overwrites, expose version history (REST/MCP/UI), and enrich the read payload so agents and humans see rich `current.md`-quality memory for project scopes like Dezeen.

**Architecture:** A small `WorkingMemoryExternalGuard` service gates `ConsolidateWorkingMemory` / refresh actions when a fresh `external` version exists. A `WorkingMemoryVersionCatalog` service lists and loads prior canonical versions. UI and API reuse existing `memory.show` structured-section partials. Elixirr `upsert_working_memory` is already implemented in IdeaTub; this plan wires operator docs and guards only (skill change is out-of-repo).

**Tech Stack:** Laravel 12, PHP 8.2+, Pest, Blade, existing MCP controller pattern

**Spec:** [2026-05-18-working-memory-hybrid-external-first-design.md](../specs/2026-05-18-working-memory-hybrid-external-first-design.md)

**Prerequisite (already shipped):** `WorkingMemoryUpsertService`, `POST /api/thoughts/working-memory/upsert`, MCP `upsert_working_memory` — see [2026-05-12-working-memory-parity.md](./2026-05-12-working-memory-parity.md).

---

## File structure

| Path | Responsibility |
|------|----------------|
| `config/working_memory.php` | Add `external_protect_days` |
| `app/Services/WorkingMemory/WorkingMemoryExternalGuard.php` | Decide if consolidated build should be skipped |
| `app/Services/WorkingMemory/WorkingMemoryVersionCatalog.php` | List/show versions for a scope |
| `app/Jobs/ConsolidateWorkingMemory.php` | Accept `$force`; call guard before build |
| `app/Http/Controllers/WorkingMemoryRefreshController.php` | Guard + flash message; optional force rebuild |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Add `canonical_version_id`, `canonical_created_at`, `source_label` to payload |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Version list + show JSON |
| `app/Http/Controllers/Api/McpController.php` | `list_working_memory_versions`, `get_working_memory_version` |
| `app/Http/Controllers/MemoryController.php` | `history`, `showVersion` web actions |
| `routes/api.php` | Version routes |
| `routes/web.php` | History routes |
| `resources/views/memory/history.blade.php` | Version list |
| `resources/views/memory/version.blade.php` | Read-only version view |
| `resources/views/memory/partials/details_card.blade.php` | Source label, canonical timestamp |
| `resources/views/memory/show.blade.php` | External badge, history link, refresh copy |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryExternalGuardTest.php` | Guard unit tests |
| `tests/Feature/WorkingMemoryExternalGuardTest.php` | Refresh + job integration |
| `tests/Feature/WorkingMemoryVersionApiTest.php` | REST list/show |
| `tests/Feature/McpWorkingMemoryVersionsTest.php` | MCP list/show |
| `tests/Feature/WorkingMemoryVersionWebTest.php` | UI history |
| `docs/mcp-integration-guide.md` | Document new MCP methods + upsert contract |

---

## Phase 1a — External protection

### Task 1: Config + unit tests for external guard

**Files:**
- Modify: `config/working_memory.php`
- Create: `app/Services/WorkingMemory/WorkingMemoryExternalGuard.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryExternalGuardTest.php`

- [ ] **Step 1: Add config key**

In `config/working_memory.php` after `consolidation_window_days`:

```php
'external_protect_days' => (int) env('WORKING_MEMORY_EXTERNAL_PROTECT_DAYS', 14),
```

- [ ] **Step 2: Write failing unit tests**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryExternalGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryExternalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_consolidation_when_fresh_external_exists(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertTrue($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: false,
        ));
    }

    public function test_force_bypasses_guard(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertFalse($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: true,
        ));
    }

    public function test_does_not_skip_when_external_is_stale(): void
    {
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDays(30),
        ]);

        $guard = app(WorkingMemoryExternalGuard::class);

        $this->assertFalse($guard->shouldSkipConsolidatedBuild(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            force: false,
        ));
    }
}
```

- [ ] **Step 3: Run tests (expect FAIL)**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryExternalGuardTest.php`

- [ ] **Step 4: Implement guard**

```php
<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;

class WorkingMemoryExternalGuard
{
    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    ) {}

    public function shouldSkipConsolidatedBuild(
        int $userId,
        string $scopeType,
        string $scopeKey,
        bool $force = false,
    ): bool {
        if ($force) {
            return false;
        }

        [$normalizedType, $normalizedKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);
        $protectDays = max(0, (int) config('working_memory.external_protect_days', 14));
        if ($protectDays === 0) {
            return false;
        }

        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $normalizedType)
            ->where('scope_key', $normalizedKey)
            ->first();

        if ($memory === null) {
            return false;
        }

        $external = $memory->versions()
            ->where('build_type', 'external')
            ->where('authoring_status', 'external')
            ->orderByDesc('created_at')
            ->first();

        if (! $external instanceof WorkingMemoryVersion) {
            return false;
        }

        return $external->created_at !== null
            && $external->created_at->gte(now()->subDays($protectDays));
    }
}
```

- [ ] **Step 5: Run unit tests (expect PASS)**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryExternalGuardTest.php`

---

### Task 2: Gate ConsolidateWorkingMemory job

**Files:**
- Modify: `app/Jobs/ConsolidateWorkingMemory.php`
- Create: `tests/Feature/WorkingMemoryExternalGuardTest.php`

- [ ] **Step 1: Write failing feature test**

```php
public function test_consolidate_job_skips_build_when_fresh_external_exists(): void
{
    $user = User::factory()->create();
    $memory = WorkingMemory::factory()->for($user)->create([
        'scope_type' => 'project',
        'scope_key' => (string) Project::factory()->for($user)->create()->id,
    ]);
    $external = WorkingMemoryVersion::factory()->for($memory)->create([
        'build_type' => 'external',
        'authoring_status' => 'external',
        'created_at' => now()->subHour(),
    ]);
    $memory->update(['latest_version_id' => $external->id]);
    $countBefore = $memory->versions()->count();

    $job = new ConsolidateWorkingMemory($user->id, 'project', $memory->scope_key);
    $job->handle(app(WorkingMemoryBuilderService::class));

    $this->assertSame($countBefore, $memory->fresh()->versions()->count());
}
```

- [ ] **Step 2: Run test (expect FAIL)**

Run: `php artisan test tests/Feature/WorkingMemoryExternalGuardTest.php --filter=test_consolidate_job_skips`

- [ ] **Step 3: Update job constructor and handle**

Add optional `public bool $force = false` to constructor (default false). At start of `handle()`:

```php
if (app(WorkingMemoryExternalGuard::class)->shouldSkipConsolidatedBuild(
    $this->userId,
    $this->scopeType,
    $this->scopeKey,
    $this->force,
)) {
    Log::info('ConsolidateWorkingMemory skipped: fresh external memory protected.', [
        'user_id' => $this->userId,
        'scope_type' => $this->scopeType,
        'scope_key' => $this->scopeKey,
    ]);

    return;
}
```

- [ ] **Step 4: Run feature tests**

Run: `php artisan test tests/Feature/WorkingMemoryExternalGuardTest.php`

---

### Task 3: Gate web refresh + add force rebuild

**Files:**
- Modify: `app/Http/Controllers/WorkingMemoryRefreshController.php`
- Modify: `tests/Feature/WorkingMemoryRefreshFeatureTest.php`
- Modify: `resources/views/components/working-memory-refresh-form.blade.php` (optional hidden `force` field on secondary button)

- [ ] **Step 1: Write failing test — project refresh blocked**

```php
public function test_project_refresh_skipped_when_fresh_external_exists(): void
{
    Queue::fake();
    config(['working_memory.external_protect_days' => 14]);
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $memory = WorkingMemory::factory()->for($owner)->create([
        'scope_type' => 'project',
        'scope_key' => (string) $project->getKey(),
    ]);
    WorkingMemoryVersion::factory()->for($memory)->create([
        'build_type' => 'external',
        'authoring_status' => 'external',
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->from(route('projects.memory.show', $project))
        ->post(route('working-memory.refresh.project', $project))
        ->assertRedirect(route('projects.memory.show', $project))
        ->assertSessionHas('info');

    Queue::assertNotPushed(ConsolidateWorkingMemory::class);
}
```

- [ ] **Step 2: Implement controller guard**

In `dispatchConsolidated()`, before `ConsolidateWorkingMemory::dispatch`:

```php
$force = $request->boolean('force');
if (app(WorkingMemoryExternalGuard::class)->shouldSkipConsolidatedBuild(
    (int) $request->user()->id,
    $normalizedType,
    $normalizedKey,
    $force,
)) {
    return; // caller handles flash — refactor to return bool or throw sentinel
}
```

Return different flash messages from each refresh action:
- Skipped: `info` — "Working memory is synced from your agent. Re-run Elixirr sync, or use Rebuild in IdeaTub to replace it."
- Queued: existing `success` message.

Add route + action `refreshProjectForce` OR pass `force=1` via a second form button `name="force" value="1"`.

- [ ] **Step 3: Update existing refresh tests** — global scope without external should still queue job.

- [ ] **Step 4: Run** `php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php tests/Feature/WorkingMemoryExternalGuardTest.php`

---

### Task 4: Enrich assembler payload for agents

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`

- [ ] **Step 1: Failing API test**

Assert `canonical_version_id`, `canonical_created_at` present; when `build_diagnostics_json.source_label` set, `source_label` echoed at top level.

- [ ] **Step 2: Add fields in `payloadFromPersistedMemory()` return array**

```php
'canonical_version_id' => (string) $canonical->id,
'canonical_created_at' => $canonical->created_at?->toIso8601String(),
'source_label' => is_array($canonical->build_diagnostics_json)
    ? ($canonical->build_diagnostics_json['source_label'] ?? null)
    : null,
```

- [ ] **Step 3: Run** `php artisan test tests/Feature/WorkingMemoryApiTest.php`

---

### Task 5: Operator — Dezeen upsert + Elixirr sync (out of repo)

**Not automated in IdeaTub CI.** Checklist for production:

- [ ] Map Dezeen → project UUID `019e0705-5591-73e9-be2e-0fb9c86b269a` in Elixirr client config.
- [ ] Update `elixirr-sync` skill: after `capture_plan`, call `upsert_working_memory` with `scope_key` = UUID, `source_label` = `elixirr-sync`.
- [ ] One-time: MCP or `curl` upsert with full `current.md` to fix ideatub.com immediately.
- [ ] Verify UI shows eight sections (not "First-pass synthesis…").

Document in `docs/mcp-integration-guide.md` under working memory.

---

## Phase 1b — Version history

### Task 6: WorkingMemoryVersionCatalog service

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryVersionCatalog.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryVersionCatalogTest.php`

- [ ] **Step 1: Unit test — list returns newest first, filters build types**

- [ ] **Step 2: Implement**

Methods:
- `listForScope(int $userId, string $scopeType, string $scopeKey, bool $includeCompactions = false): LengthAwarePaginator`
- `showForUser(int $userId, string $versionId): WorkingMemoryVersion` (404 if wrong user)
- `toListItem(WorkingMemoryVersion $v): array` — id, created_at, build_type, authoring_status, confidence_score, source_label, citation_coverage
- `toDetailPayload(WorkingMemoryVersion $v): array` — list fields + structured_sections, summary_markdown, section_references

Default query: `whereIn('build_type', ['external', 'consolidated'])` unless `$includeCompactions`, then also `where('build_type', 'like', 'compaction:%')`.

- [ ] **Step 3: Run unit tests**

---

### Task 7: REST version endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/WorkingMemoryVersionApiTest.php`

- [ ] **Step 1: Routes**

```php
Route::get('/working-memory/versions', [ThoughtsApiController::class, 'workingMemoryVersions']);
Route::get('/working-memory/versions/{version}', [ThoughtsApiController::class, 'workingMemoryVersion'])
    ->whereUuid('version');
```

- [ ] **Step 2: Controller methods** — validate `scope_type`, `scope_key`, optional `include=compactions`, paginate `per_page` max 50.

- [ ] **Step 3: Feature tests** — auth required, isolation between users, list + show shapes.

Run: `php artisan test tests/Feature/WorkingMemoryVersionApiTest.php`

---

### Task 8: MCP version methods

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Create: `tests/Feature/McpWorkingMemoryVersionsTest.php`

- [ ] **Step 1: Add to `mcpMethodNames()` and handler map**

- `list_working_memory_versions` — params: scope_type, scope_key, include_compactions (bool), page, per_page
- `get_working_memory_version` — params: version_id

- [ ] **Step 2: Tool descriptors in tools/list** (mirror REST)

- [ ] **Step 3: MCP feature tests** — JSON-RPC success + auth errors

Run: `php artisan test tests/Feature/McpWorkingMemoryVersionsTest.php`

---

### Task 9: Web UI — history list + version detail

**Files:**
- Modify: `app/Http/Controllers/MemoryController.php`
- Modify: `routes/web.php`
- Create: `resources/views/memory/history.blade.php`
- Create: `resources/views/memory/version.blade.php`
- Create: `tests/Feature/WorkingMemoryVersionWebTest.php`

- [ ] **Step 1: Routes** (inside `working.memory.ui` middleware group)

```php
Route::get('/memory/versions', [MemoryController::class, 'historyGlobal'])->name('memory.versions');
Route::get('/projects/{project}/memory/versions', [MemoryController::class, 'historyProject'])->name('projects.memory.versions');
Route::get('/memory/versions/{version}', [MemoryController::class, 'showVersion'])->name('memory.version.show');
```

- [ ] **Step 2: Controller** — authorize project; paginate versions; version show uses same structured partials as `memory.show` with `$readOnly = true` and banner "Historical snapshot from {date}".

- [ ] **Step 3: Add History link** on `memory/show.blade.php` header next to refresh.

- [ ] **Step 4: Feature tests** — owner can list; other user 403; version show renders section heading from fixture external version.

Run: `php artisan test tests/Feature/WorkingMemoryVersionWebTest.php`

---

### Task 10: UI polish — details card, external badge, refresh copy

**Files:**
- Modify: `resources/views/memory/partials/details_card.blade.php`
- Modify: `resources/views/memory/show.blade.php`
- Modify: `tests/Feature/WorkingMemoryWebTest.php` (or project memory test)

- [ ] **Step 1: Details card** — rows: Source (`source_label` or —), Canonical version (`canonical_created_at`), Authoring status.

- [ ] **Step 2: External badge** — when `baseline_build_type === 'external'`, show pill "Synced from agent".

- [ ] **Step 3: Refresh area** — primary button unchanged when no external; when external protected, show info text + secondary "Rebuild in IdeaTub" with `force=1` (only if AI authoring enabled, else hide or disabled with tooltip).

- [ ] **Step 4: Assertion in web test** — external version page contains "Current Focus" not "Executive summary".

---

### Task 11: Documentation

**Files:**
- Modify: `docs/mcp-integration-guide.md`
- Modify: `CLAUDE.md` (working memory section — upsert + version list)

- [ ] Document `list_working_memory_versions`, `get_working_memory_version`, `canonical_version_id`, external-first workflow, project UUID scope_key requirement.

---

## Verification (full suite)

```bash
php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryExternalGuardTest.php
php artisan test tests/Feature/WorkingMemoryExternalGuardTest.php
php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php
php artisan test tests/Feature/WorkingMemoryVersionApiTest.php
php artisan test tests/Feature/McpWorkingMemoryVersionsTest.php
php artisan test tests/Feature/WorkingMemoryVersionWebTest.php
php artisan test tests/Feature/WorkingMemoryApiTest.php
```

---

## Spec coverage self-review

| Spec requirement | Task |
|------------------|------|
| External protect on refresh | 2, 3 |
| Force rebuild | 3 |
| Version list/read REST | 7 |
| Version list/read MCP | 8 |
| Version history UI | 9 |
| Details + badge + refresh copy | 10 |
| canonical_version_id in get_working_memory | 4 |
| Elixirr upsert + UUID mapping | 5 (operator) |
| Phases 2–3 (capture_meeting, AI) | Out of scope — parity spec |

---

## Out of scope (follow-up plans)

- Phase 2: meeting/automation capture hooks ([2026-05-12-working-memory-parity.md](./2026-05-12-working-memory-parity.md))
- Phase 3: AI authoring for non-external scopes
- `WORKING_MEMORY_CANONICAL_VERSION_RETAIN_COUNT` pruning

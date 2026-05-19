# Elixirr Client ↔ IdeaTub Project Scopes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Map Elixirr clients and subprojects to a two-level IdeaTub Project tree, expose UUID discovery via `list_projects`, roll up client-tagged and child-project thoughts into client-root working memory, and show a **Clients**-grouped memory index.

**Architecture:** Extend `projects` with `parent_project_id` and `elixirr_*_slug` columns. Add `ProjectScopeMatcher` for hierarchical thought inclusion when `scope_key` is a client-root UUID. Extend `WorkingMemoryScopesIndexBuilder` to emit nested **Clients** rows. Elixirr sync (out of repo) resolves UUIDs via `list_projects` + `ideatub-scope.json` cache.

**Tech Stack:** Laravel 12, PHP 8.2+, Pest, Blade, MCP JSON-RPC (`McpController`), OAuth bearer REST

**Spec:** [2026-05-19-elixirr-client-project-scopes-design.md](../specs/2026-05-19-elixirr-client-project-scopes-design.md)

**Prerequisites (already shipped):** `upsert_working_memory`, external-first hybrid — [2026-05-18-working-memory-hybrid-external-first-design.md](../specs/2026-05-18-working-memory-hybrid-external-first-design.md)

---

## File structure

| Path | Responsibility |
|------|----------------|
| `database/migrations/2026_05_19_*_add_elixirr_fields_to_projects_table.php` | Schema + partial unique index |
| `app/Models/Project.php` | Parent/child relations, slug fillable |
| `database/factories/ProjectFactory.php` | `clientRoot()`, `elixirrChild()` states |
| `app/Services/Projects/ProjectScopeMatcher.php` | Thought ∈ project scope (root vs child) |
| `app/Services/Projects/ProjectListingService.php` | Query + DTO for list API/MCP |
| `app/Http/Controllers/Api/ProjectsApiController.php` | `GET /api/projects` |
| `app/Http/Controllers/Api/McpController.php` | `list_projects` method + tool schema |
| `routes/api.php` | Register projects route |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Delegate project filter to matcher |
| `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php` | Clients / Other projects sections |
| `resources/views/memory/scopes/index.blade.php` | Nested sub-rows (`depth` / `is_child`) |
| `resources/views/projects/edit.blade.php` | Optional slug fields (operator linking) |
| `app/Http/Requests/UpdateProjectRequest.php` | Validate slug fields |
| `app/Console/Commands/MigrateWorkingMemoryProjectScopeKeysCommand.php` | Slug → UUID WM migration |
| `config/working_memory.php` | `require_uuid_scope_key_for_elixirr_sync` optional flag |
| `app/Services/WorkingMemory/WorkingMemoryUpsertService.php` | Optional UUID validation |
| `tests/Unit/Services/Projects/ProjectScopeMatcherTest.php` | Roll-up unit tests |
| `tests/Feature/ProjectsApiTest.php` | REST list |
| `tests/Feature/McpListProjectsTest.php` | MCP list |
| `tests/Feature/MemoryScopesIndexTest.php` | Clients grouping (extend) |
| `tests/Feature/WorkingMemoryClientRollupTest.php` | Builder integration |
| `docs/mcp-integration-guide.md` | Document `list_projects` |
| `~/.codex/skills/elixirr-sync/` (out of repo) | Script + SKILL — phase 3 operator runbook |

---

## Phase 1 — Project schema + discovery API

### Task 1: Migration for Elixirr project fields

**Files:**
- Create: `database/migrations/2026_05_19_120000_add_elixirr_fields_to_projects_table.php`

- [ ] **Step 1: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('parent_project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('projects')
                ->nullOnDelete();
            $table->string('elixirr_client_slug', 64)->nullable()->after('description');
            $table->string('elixirr_project_slug', 64)->nullable()->after('elixirr_client_slug');
            $table->index(['user_id', 'elixirr_client_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_project_id');
            $table->dropColumn(['elixirr_client_slug', 'elixirr_project_slug']);
        });
    }
};
```

**Note:** Do **not** add a unique on `(user_id, elixirr_client_slug)` alone — child projects share the same client slug. Enforce “at most one client root per slug” in `UpdateProjectRequest` / `StoreProjectRequest` (Task 5): reject save when another root exists with same `elixirr_client_slug`, `parent_project_id` null, and `elixirr_project_slug` null.

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: migration OK

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_19_120000_add_elixirr_fields_to_projects_table.php
git commit -m "feat(projects): add Elixirr parent and slug columns"
```

---

### Task 2: Project model + factory states

**Files:**
- Modify: `app/Models/Project.php`
- Modify: `database/factories/ProjectFactory.php`

- [ ] **Step 1: Write failing test** — add to `tests/Unit/Models/ProjectTest.php` (create file if missing):

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_belongs_to_parent(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->for($user)->create([
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => null,
            'parent_project_id' => null,
        ]);
        $child = Project::factory()->for($user)->create([
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => 'foo',
            'parent_project_id' => $root->id,
        ]);

        $this->assertTrue($child->parent->is($root));
        $this->assertTrue($root->children->contains($child));
    }
}
```

- [ ] **Step 2: Run test**

Run: `php artisan test --filter=test_child_belongs_to_parent`
Expected: FAIL (relations missing)

- [ ] **Step 3: Implement model**

In `app/Models/Project.php` add to `$fillable`: `parent_project_id`, `elixirr_client_slug`, `elixirr_project_slug`.

```php
public function parent(): BelongsTo
{
    return $this->belongsTo(Project::class, 'parent_project_id');
}

/** @return HasMany<Project, $this> */
public function children(): HasMany
{
    return $this->hasMany(Project::class, 'parent_project_id');
}

public function isElixirrClientRoot(): bool
{
    return $this->parent_project_id === null
        && $this->elixirr_client_slug !== null
        && $this->elixirr_project_slug === null;
}
```

Factory states:

```php
public function elixirrClientRoot(string $clientSlug = 'dezeen'): static
{
    return $this->state(fn () => [
        'title' => str($clientSlug)->title()->toString(),
        'elixirr_client_slug' => $clientSlug,
        'elixirr_project_slug' => null,
        'parent_project_id' => null,
    ]);
}

public function elixirrChild(Project $parent, string $projectSlug): static
{
    return $this->state(fn () => [
        'title' => str($projectSlug)->title()->toString(),
        'elixirr_client_slug' => $parent->elixirr_client_slug,
        'elixirr_project_slug' => $projectSlug,
        'parent_project_id' => $parent->id,
    ]);
}
```

- [ ] **Step 4: Run test** — Expected: PASS

- [ ] **Step 5: Commit**

---

### Task 3: `ProjectListingService` + REST `GET /api/projects`

**Files:**
- Create: `app/Services/Projects/ProjectListingService.php`
- Create: `app/Http/Controllers/Api/ProjectsApiController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/ProjectsApiTest.php`

- [ ] **Step 1: Failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_projects_for_oauth_user(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrChild($root, 'foo')->for($user)->create();

        $token = $this->issueOAuthTokenFor($user); // use existing OAuth test helper in repo

        $response = $this->getJson('/api/projects?elixirr_client_slug=dezeen', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.elixirr_client_slug', 'dezeen');
    }
}
```

Adapt auth helper to match `tests/Feature/WorkingMemoryApiTest.php` OAuth pattern.

- [ ] **Step 2: Implement service + controller**

`ProjectListingService::forUser(int $userId, ?string $elixirrClientSlug, ?string $parentProjectId): array`

Returns `['data' => [...]]` each item:

```php
[
    'id' => (string) $project->id,
    'title' => $project->title,
    'elixirr_client_slug' => $project->elixirr_client_slug,
    'elixirr_project_slug' => $project->elixirr_project_slug,
    'parent_project_id' => $project->parent_project_id,
]
```

`ProjectsApiController@index` — validate query, call service, return JSON.

`routes/api.php` inside `auth.oauth.bearer` group (sibling to `thoughts`):

```php
Route::get('/projects', [ProjectsApiController::class, 'index']);
```

- [ ] **Step 3: Run tests** — `php artisan test tests/Feature/ProjectsApiTest.php`

- [ ] **Step 4: Commit**

---

### Task 4: MCP `list_projects`

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Create: `tests/Feature/McpListProjectsTest.php`
- Modify: `docs/mcp-integration-guide.md`

- [ ] **Step 1: Add to `mcpMethodNames()`** after `get_working_memory`:

```php
'list_projects',
```

- [ ] **Step 2: Failing MCP test**

```php
public function test_list_projects_via_mcp(): void
{
    [$key, $user] = $this->validKeyAndUser();
    Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

    $response = $this->mcpPost($key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'list_projects',
        'params' => ['elixirr_client_slug' => 'dezeen'],
    ]);

    $response->assertOk();
    $response->assertJsonPath('result.data.0.elixirr_client_slug', 'dezeen');
}
```

- [ ] **Step 3: Implement dispatch**

```php
'list_projects' => $this->listProjects($params),
```

```php
private function listProjects(array $params): array
{
    $v = Validator::make($params, [
        'elixirr_client_slug' => 'sometimes|string|max:64',
        'parent_project_id' => 'sometimes|uuid',
    ]);
    if ($v->fails()) {
        throw new \InvalidArgumentException($v->errors()->first());
    }

    return app(ProjectListingService::class)->forUser(
        (int) auth()->id(),
        isset($params['elixirr_client_slug']) ? (string) $params['elixirr_client_slug'] : null,
        isset($params['parent_project_id']) ? (string) $params['parent_project_id'] : null,
    );
}
```

Add tool entry in `respondToolsList()` mirroring REST params.

- [ ] **Step 4: Document in `docs/mcp-integration-guide.md`** — table row for `list_projects` + example JSON-RPC.

- [ ] **Step 5: Run** `php artisan test tests/Feature/McpListProjectsTest.php`

- [ ] **Step 6: Commit**

---

### Task 5: Project edit UI for slug linking (operator)

**Files:**
- Modify: `app/Http/Requests/UpdateProjectRequest.php`
- Modify: `app/Http/Controllers/ProjectController.php` (`update` passes new fields)
- Modify: `resources/views/projects/edit.blade.php`

- [ ] **Step 1: Add validation rules**

```php
'elixirr_client_slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
'elixirr_project_slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
'parent_project_id' => ['nullable', 'uuid', 'exists:projects,id'],
```

Custom rule: if `elixirr_project_slug` is set, `parent_project_id` required.

- [ ] **Step 2: Add three fields to edit form** (labels: “Elixirr client slug”, “Elixirr project slug”, “Parent project” select of user’s root projects)

- [ ] **Step 3: Manual smoke** — edit Dezeen project, set `elixirr_client_slug=dezeen`, save

- [ ] **Step 4: Commit**

---

## Phase 2 — Client-scope thought roll-up

### Task 6: `ProjectScopeMatcher`

**Files:**
- Create: `app/Services/Projects/ProjectScopeMatcher.php`
- Create: `tests/Unit/Services/Projects/ProjectScopeMatcherTest.php`

- [ ] **Step 1: Unit tests (failing)**

Cases:

1. Client root UUID — thought linked to root → true
2. Client root UUID — thought linked only to child → true
3. Client root UUID — thought tagged `client:dezeen` only → true
4. Client root UUID — thought with `source_metadata.project=dezeen` → true
5. Client root UUID — sibling child thought → false
6. Child UUID — thought linked to child → true
7. Child UUID — thought with `source_metadata.project=dezeen/foo` → true
8. Non-UUID scope_key `dezeen` — legacy exact metadata match only (preserve today)

- [ ] **Step 2: Implement**

```php
final class ProjectScopeMatcher
{
    public function thoughtMatchesProjectScope(Thought $thought, string $scopeKey, ?Project $scopeProject): bool
    {
        if ($scopeProject !== null) {
            if ($scopeProject->isElixirrClientRoot()) {
                return $this->matchesClientRoot($thought, $scopeProject);
            }
            if ($scopeProject->parent_project_id !== null) {
                return $this->matchesChildProject($thought, $scopeProject);
            }
        }

        return $this->matchesLegacySlugScope($thought, $scopeKey);
    }
    // ... private helpers for link, tag client:{slug}, metadata exact, child id list
}
```

Preload: accept optional `Collection<string, string>` of child project IDs for root to avoid N+1 in builder (load once per build).

- [ ] **Step 3: Run** `php artisan test tests/Unit/Services/Projects/ProjectScopeMatcherTest.php`

- [ ] **Step 4: Commit**

---

### Task 7: Wire matcher into `WorkingMemoryBuilderService`

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Create: `tests/Feature/WorkingMemoryClientRollupTest.php`

- [ ] **Step 1: Feature test**

```php
public function test_client_root_consolidated_includes_child_linked_thought(): void
{
    $user = User::factory()->create();
    $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
    $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
    $thought = Thought::factory()->for($user)->create();
    $child->thoughts()->attach($thought->id, ['sort_order' => 1]);

    $ids = app(WorkingMemoryBuilderService::class)
        ->selectThoughtsForTest($user->id, 'project', (string) $root->id, 'consolidated')
        ->pluck('id');

    $this->assertTrue($ids->contains($thought->id));
}
```

Expose package-private test hook: either make `selectThoughts` public `@internal` for tests, or test via `buildConsolidated` output `working_memory_inputs` count — prefer testing through package-visible method `collectThoughtsForScope` if you add a thin public wrapper used only in tests.

Minimal approach: call `buildConsolidated` and assert `WorkingMemoryInput` rows reference the child-only thought.

- [ ] **Step 2: In `selectThoughts` project branch**, replace inline filter:

```php
$scopeProject = Str::isUuid($scopeKey)
    ? Project::query()->where('user_id', $userId)->find($scopeKey)
    : null;

return app(ProjectScopeMatcher::class)
    ->thoughtMatchesProjectScope($thought, $scopeKey, $scopeProject);
```

- [ ] **Step 3: Run rollup tests**

- [ ] **Step 4: Commit**

---

### Task 8: Optional upsert UUID guard for `elixirr-sync`

**Files:**
- Modify: `config/working_memory.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryUpsertService.php`
- Modify: `tests/Feature/McpUpsertWorkingMemoryTest.php`

- [ ] **Step 1: Config**

```php
'require_uuid_project_scope_key_for_source_labels' => ['elixirr-sync'],
```

- [ ] **Step 2: Test** — upsert with `source_label=elixirr-sync` and `scope_key=dezeen` → 422/InvalidArgumentException

- [ ] **Step 3: Validate in upsert normalizer** when label in list and `scope_type=project` → `Str::isUuid($scopeKey)`

- [ ] **Step 4: Commit**

---

## Phase 3 — Memory index Clients section

### Task 9: `WorkingMemoryScopesIndexBuilder` grouped output

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php`
- Modify: `tests/Feature/MemoryScopesIndexTest.php`
- Modify: `resources/views/memory/scopes/index.blade.php`

- [ ] **Step 1: Change builder return shape**

Replace single `projects` section with:

```php
['key' => 'clients', 'title' => 'Clients', 'groups' => [
  ['client_slug' => 'dezeen', 'client_title' => 'Dezeen', 'rows' => [
      ['title' => 'Dezeen', 'href' => ..., 'depth' => 0, ...],
      ['title' => 'Foo', 'href' => ..., 'depth' => 1, ...],
  ]],
]]
['key' => 'other_projects', 'title' => 'Other projects', 'rows' => [...]]
```

Algorithm:

1. Load all user projects with elixirr fields into map by id.
2. For each `project` scoped `WorkingMemory`, resolve `Project` by UUID; if `isElixirrClientRoot()` or child with parent, bucket under `clients[client_slug]`; else `other_projects`.
3. Legacy slug `scope_key` without project: parse `dezeen/foo` for grouping or push to `other_projects`.

- [ ] **Step 2: Update test `test_sections_ordered_and_sorted`**

Expect headings: `Global`, `Insights`, `Clients`, `Other projects` (Tags if present).

Add `test_clients_section_nests_subprojects` with root + child memories.

- [ ] **Step 3: Blade** — for `clients` section, loop `groups` then `rows`; if `depth === 1`, add `ml-6` indent class on `<li>`.

- [ ] **Step 4: Optional Stream link** on client root row: `route('idea.stream', ['tag' => TagSlug::from('client:dezeen')])` as secondary text link “Stream”.

- [ ] **Step 5: Run** `php artisan test tests/Feature/MemoryScopesIndexTest.php`

- [ ] **Step 6: Commit**

---

## Phase 4 — Migrate legacy WM scope keys

### Task 10: Artisan command

**Files:**
- Create: `app/Console/Commands/MigrateWorkingMemoryProjectScopeKeysCommand.php`
- Create: `tests/Feature/MigrateWorkingMemoryProjectScopeKeysCommandTest.php`

- [ ] **Step 1: Command signature**

`php artisan working-memory:migrate-project-scope-keys {--user=} {--dry-run}`

For each `working_memories` where `scope_type=project` and `scope_key` is not UUID:

- `dezeen` → find root project with `elixirr_client_slug=dezeen`
- `dezeen/foo` → find child with slugs
- Update `scope_key` to UUID or log skip

- [ ] **Step 2: Tests with factory memories + projects**

- [ ] **Step 3: Document in spec support or `dev/` note** — run once for production Dezeen

- [ ] **Step 4: Commit**

---

## Phase 5 — Elixirr sync (out of repo)

### Task 11: Update `elixirr-sync` skill + script

**Files (operator machine, not IdeaTub repo):**

- `~/.codex/skills/elixirr-sync/scripts/sync_project_working_memory_to_ideatub.py`
- `~/.codex/skills/elixirr-sync/SKILL.md`

- [ ] **Step 1: Add payload field `ideatub_scope_key`** (UUID) separate from capture `project` slug

- [ ] **Step 2: Implement `resolve_scope_mapping(client, project=None)`**

1. Read `clients/<client>/ideatub-scope.json`
2. If incomplete, POST MCP `list_projects` with `elixirr_client_slug`
3. Write cache; error if missing

- [ ] **Step 3: Add CLI flags** `--refresh-mapping`, emit UUID in JSON for agent upsert step

- [ ] **Step 4: SKILL.md** — document `list_projects`, cache path, upsert uses UUID only

- [ ] **Step 5: Manual E2E** — sync Dezeen `current.md`, verify `get_working_memory` with UUID returns external body

---

## Phase 6 — Production onboarding (operator checklist)

- [ ] Create/link IdeaTub **Dezeen** root project; set `elixirr_client_slug=dezeen`
- [ ] Create child projects per Elixirr subfolders; set `elixirr_project_slug` + parent
- [ ] Run `working-memory:migrate-project-scope-keys` (dry-run first)
- [ ] Run Elixirr sync with `upsert_working_memory` + `source_label=elixirr-sync`
- [ ] Verify `/memory/scopes` shows **Clients → Dezeen → subprojects**
- [ ] Verify `/stream?tag=client-dezeen` shows client-tagged captures

---

## Spec coverage self-review

| Spec requirement | Task |
|------------------|------|
| 2B project tree schema | 1–2 |
| `list_projects` MCP + REST | 3–4 |
| `ideatub-scope.json` cache | 11 (out of repo) |
| Client roll-up rules | 6–7 |
| Memory index Clients section | 9 |
| UUID upsert for elixirr-sync | 8, 11 |
| Legacy slug migration | 10 |
| Stream tag unchanged | 9 (optional link) |
| Do not add `client` scope type | — (by design) |
| One client root per slug | App validation in Task 5 (not DB unique on client_slug alone) |

No TBD placeholders in task steps.

---

## Test commands (full suite touch)

```bash
php artisan test tests/Unit/Services/Projects/ProjectScopeMatcherTest.php
php artisan test tests/Feature/ProjectsApiTest.php
php artisan test tests/Feature/McpListProjectsTest.php
php artisan test tests/Feature/WorkingMemoryClientRollupTest.php
php artisan test tests/Feature/MemoryScopesIndexTest.php
php artisan test tests/Feature/MigrateWorkingMemoryProjectScopeKeysCommandTest.php
```

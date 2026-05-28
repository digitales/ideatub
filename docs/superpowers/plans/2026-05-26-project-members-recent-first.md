# Project Members Recent-First Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show project member thoughts newest-updated first on project show and shared project list surfaces, while keeping graph ordering unchanged.

**Architecture:** Keep `Project::thoughts()` pivot `sort_order` behavior intact for graph/internal semantics. Add one reusable display-order helper on `Project` and use it only in list-style controllers (`ProjectController@show`, `SharedProjectViewController@renderHub`, `SharedProjectViewController@renderReadAll`).

**Tech Stack:** Laravel 12, Eloquent relationships/scopes, Pest feature tests.

---

## File Structure

- Modify: `app/Models/Project.php`
  - Add a reusable helper that applies display sorting (`thoughts.updated_at DESC`, `thoughts.id ASC`) to a thoughts query.
- Modify: `app/Http/Controllers/ProjectController.php`
  - Use display sort helper when eager-loading project members for the authenticated project page.
- Modify: `app/Http/Controllers/SharedProjectViewController.php`
  - Use display sort helper for shared hub and read-all member retrieval.
- Modify: `tests/Feature/ProjectCrudTest.php`
  - Add regression test proving project members are rendered by `updated_at` desc.
- Modify: `tests/Feature/SharedProjectViewTest.php`
  - Add hub/read-all ordering tests proving newest-updated first.

### Task 1: Add reusable display ordering helper on `Project`

**Files:**
- Modify: `app/Models/Project.php`
- Test: `tests/Feature/ProjectCrudTest.php`

- [ ] **Step 1: Write the failing test for project-page ordering**

```php
test('project show members are ordered by thought updated_at descending', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $older = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Older member content',
        'updated_at' => now()->subHour(),
    ]);
    $newer = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Newer member content',
        'updated_at' => now(),
    ]);

    // Intentionally opposite pivot order so display order must come from updated_at.
    $project->thoughts()->attach($newer->id, ['sort_order' => 1]);
    $project->thoughts()->attach($older->id, ['sort_order' => 0]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeInOrder(['Newer member content', 'Older member content'], false);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ProjectCrudTest.php --filter="project show members are ordered by thought updated_at descending"`
Expected: FAIL; response order reflects pivot `sort_order` instead of `updated_at`.

- [ ] **Step 3: Add minimal model helper implementation**

```php
public function orderMembersForDisplay($query)
{
    return $query
        ->orderByDesc('thoughts.updated_at')
        ->orderBy('thoughts.id');
}
```

- [ ] **Step 4: Run test to confirm failure still occurs (controller not wired yet)**

Run: `php artisan test tests/Feature/ProjectCrudTest.php --filter="project show members are ordered by thought updated_at descending"`
Expected: FAIL; helper exists but not yet used in query path.

- [ ] **Step 5: Commit helper only**

```bash
git add app/Models/Project.php tests/Feature/ProjectCrudTest.php
git commit -m "feat: add project member display ordering helper"
```

### Task 2: Apply display ordering on authenticated project page

**Files:**
- Modify: `app/Http/Controllers/ProjectController.php`
- Test: `tests/Feature/ProjectCrudTest.php`

- [ ] **Step 1: Wire helper into project show eager load**

```php
$project->load(['thoughts' => function ($q) use ($project) {
    $project->orderMembersForDisplay($q);
}]);
```

- [ ] **Step 2: Run focused test to verify pass**

Run: `php artisan test tests/Feature/ProjectCrudTest.php --filter="project show members are ordered by thought updated_at descending"`
Expected: PASS.

- [ ] **Step 3: Run nearby regression test set**

Run: `php artisan test tests/Feature/ProjectCrudTest.php`
Expected: PASS; no regressions to create/view/add/remove behavior.

- [ ] **Step 4: Commit controller + tests**

```bash
git add app/Http/Controllers/ProjectController.php tests/Feature/ProjectCrudTest.php
git commit -m "feat: sort project members by latest thought update"
```

### Task 3: Apply display ordering on shared hub and read-all

**Files:**
- Modify: `app/Http/Controllers/SharedProjectViewController.php`
- Test: `tests/Feature/SharedProjectViewTest.php`

- [ ] **Step 1: Write failing shared ordering tests**

```php
public function test_hub_members_are_ordered_by_updated_at_desc(): void
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $older = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Shared older',
        'updated_at' => now()->subHour(),
    ]);
    $newer = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Shared newer',
        'updated_at' => now(),
    ]);

    $project->thoughts()->attach($older->id, ['sort_order' => 0]);
    $project->thoughts()->attach($newer->id, ['sort_order' => 1]);

    $share = ProjectShare::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'token' => ProjectShare::generateToken(),
    ]);

    $this->get(route('shared-projects.hub', $share->token))
        ->assertOk()
        ->assertSeeInOrder(['Shared newer', 'Shared older'], false);
}

public function test_read_all_members_are_ordered_by_updated_at_desc(): void
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $older = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => '## Older' . PHP_EOL . PHP_EOL . 'Older body',
        'updated_at' => now()->subHour(),
    ]);
    $newer = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => '## Newer' . PHP_EOL . PHP_EOL . 'Newer body',
        'updated_at' => now(),
    ]);

    $project->thoughts()->attach($older->id, ['sort_order' => 0]);
    $project->thoughts()->attach($newer->id, ['sort_order' => 1]);

    $share = ProjectShare::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'token' => ProjectShare::generateToken(),
    ]);

    $this->get(route('shared-projects.read', $share->token))
        ->assertOk()
        ->assertSeeInOrder(['Newer body', 'Older body'], false);
}
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test tests/Feature/SharedProjectViewTest.php --filter="ordered_by_updated_at_desc|ordered by updated_at desc"`
Expected: FAIL; current query uses pivot `sort_order`.

- [ ] **Step 3: Update shared controller queries to use helper**

```php
// renderHub
$project->load(['thoughts' => fn ($q) => $project->orderMembersForDisplay($q)]);

// renderReadAll
$thoughts = $project->orderMembersForDisplay($project->thoughts())->get();
```

If needed for cleaner typing, use this equivalent pattern:

```php
$thoughts = $project->thoughts()
    ->orderByDesc('thoughts.updated_at')
    ->orderBy('thoughts.id')
    ->get();
```

Preferred: helper-based consistency.

- [ ] **Step 4: Run focused shared tests**

Run: `php artisan test tests/Feature/SharedProjectViewTest.php`
Expected: PASS.

- [ ] **Step 5: Commit shared-surface changes**

```bash
git add app/Http/Controllers/SharedProjectViewController.php tests/Feature/SharedProjectViewTest.php
git commit -m "feat: use recent-first ordering on shared project members"
```

### Task 4: Regression pass and cleanup verification

**Files:**
- Verify only: project/member ordering files and related tests
- Optional touch-up: `app/Http/Controllers/ProjectGraphController.php` (no code change expected)

- [ ] **Step 1: Run full targeted suite**

Run:

```bash
php artisan test tests/Feature/ProjectCrudTest.php tests/Feature/SharedProjectViewTest.php tests/Feature/ProjectModelRelationsTest.php tests/Unit/Services/ProjectMembershipServiceTest.php
```

Expected:
- PASS all tests.
- `ProjectModelRelationsTest` still confirms default relation pivot order.
- `ProjectMembershipServiceTest` still confirms reorder semantics.

- [ ] **Step 2: Sanity-check graph ordering remains unchanged**

Run: `php artisan test tests/Feature/ProjectGraphAndShareManagementTest.php`
Expected: PASS; graph data path unaffected.

- [ ] **Step 3: Review diff scope**

Run: `git diff --name-only HEAD~4..HEAD`
Expected: only model/controller/test files related to member ordering.

- [ ] **Step 4: Final consolidation commit (if needed)**

```bash
git add -A
git commit -m "test: add regression coverage for project member display ordering"
```

Only commit if there are remaining unstaged/staged changes after prior task commits.


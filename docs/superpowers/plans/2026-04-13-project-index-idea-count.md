# Project index top-level idea count — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each project’s count of linked **top-level** thoughts (`parent_id` null) on `GET /projects`, with correct singular/plural copy and efficient querying.

**Architecture:** Add a constrained `withCount` on `Project::index()`’s `thoughts` relationship (alias `top_level_ideas_count`, `whereNull('thoughts.parent_id')`). Render that attribute on `resources/views/projects/index.blade.php` beside the existing relative “Updated” time. Cover behaviour with two Pest feature tests in `ProjectCrudTest.php`.

**Tech stack:** Laravel 12, Eloquent, Blade, Pest, SQLite (tests).

**Spec:** [docs/superpowers/specs/2026-04-13-project-index-idea-count-design.md](../specs/2026-04-13-project-index-idea-count-design.md)

---

## File map

| File | Role |
|------|------|
| `app/Http/Controllers/ProjectController.php` | Add `withCount` to `index()` query |
| `resources/views/projects/index.blade.php` | Show `top_level_ideas_count` + “Updated …” on each card |
| `tests/Feature/ProjectCrudTest.php` | Two new feature tests |

---

### Task 1: Feature tests (expect failure until Task 2–3)

**Files:**
- Modify: `tests/Feature/ProjectCrudTest.php`

- [ ] **Step 1: Append two Pest tests**

Add after the existing tests (before the closing of the file — keep `uses(RefreshDatabase::class);` at top unchanged).

```php
test('projects index shows zero ideas when project has no members', function () {
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id, 'title' => 'Empty project']);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Empty project', false)
        ->assertSee('0 ideas', false);
});

test('projects index counts only top-level thoughts linked to project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'Grouped project']);
    $rootA = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);
    $rootB = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);
    $child = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => $rootA->id]);

    $this->actingAs($user);

    foreach ([$rootA, $rootB, $child] as $thought) {
        $this->post(route('projects.thoughts.store', $project), ['thought_id' => $thought->id])
            ->assertRedirect();
    }

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Grouped project', false)
        ->assertSee('2 ideas', false);
});
```

- [ ] **Step 2: Run tests — expect FAIL**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/ProjectCrudTest.php --filter="projects index"
```

Expected: **FAIL** — response HTML lacks `0 ideas` / `2 ideas` (or `top_level_ideas_count` not loaded).

---

### Task 2: Eager count in `ProjectController::index`

**Files:**
- Modify: `app/Http/Controllers/ProjectController.php` (method `index`, lines ~18–21)

- [ ] **Step 1: Replace the `Project::query()` chain**

Current:

```php
        $projects = Project::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get();
```

Replace with:

```php
        $projects = Project::query()
            ->where('user_id', auth()->id())
            ->withCount([
                'thoughts as top_level_ideas_count' => function ($query) {
                    $query->whereNull('thoughts.parent_id');
                },
            ])
            ->orderByDesc('updated_at')
            ->get();
```

- [ ] **Step 2: Run filtered tests — still FAIL until Task 3**

Run:

```bash
php artisan test tests/Feature/ProjectCrudTest.php --filter="projects index"
```

Expected: **FAIL** on `assertSee('0 ideas')` / `assertSee('2 ideas')` if the Blade view is not updated yet.

---

### Task 3: Blade — show count on each project card

**Files:**
- Modify: `resources/views/projects/index.blade.php`

- [ ] **Step 1: Replace the footer line inside the `@foreach`**

Current:

```blade
                        <p class="text-[11px] text-slate-brand/45 mt-2">Updated {{ $project->updated_at->diffForHumans() }}</p>
```

Replace with:

```blade
                        <p class="text-[11px] text-slate-brand/45 mt-2">{{ $project->top_level_ideas_count === 1 ? '1 idea' : $project->top_level_ideas_count.' ideas' }} · Updated {{ $project->updated_at->diffForHumans() }}</p>
```

Note: `0 ideas` is rendered by the `else` branch (`0 ideas`).

- [ ] **Step 2: Run filtered tests — expect PASS**

Run:

```bash
php artisan test tests/Feature/ProjectCrudTest.php --filter="projects index"
```

Expected: **PASS**

- [ ] **Step 3: Run full `ProjectCrudTest` — expect PASS**

Run:

```bash
php artisan test tests/Feature/ProjectCrudTest.php
```

Expected: **PASS**

---

### Task 4: Commit

**Files:** (staged changes from Tasks 1–3)

- [ ] **Step 1: Commit**

```bash
git add app/Http/Controllers/ProjectController.php resources/views/projects/index.blade.php tests/Feature/ProjectCrudTest.php
git commit -m "feat(projects): show top-level idea count on index"
```

---

## Self-review (spec coverage)

| Spec requirement | Task |
|------------------|------|
| Top-level only (`thoughts.parent_id` null) | Task 2 `withCount` constraint; Task 3 test with child |
| Alias `top_level_ideas_count` | Task 2 |
| Copy: 0 / 1 / N ideas | Task 3 Blade ternary |
| Same card styling as “Updated” | Task 3 same `<p>` class |
| No N+1 / no full thought load | Task 2 `withCount` |
| Feature tests (0, 2+child) | Task 1 |
| No migrations | — |

Placeholder scan: none.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-13-project-index-idea-count.md`. Two execution options:

**1. Subagent-driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration (`superpowers:subagent-driven-development`).

**2. Inline execution** — Run tasks in this session with checkpoints (`superpowers:executing-plans`).

Which approach do you want?

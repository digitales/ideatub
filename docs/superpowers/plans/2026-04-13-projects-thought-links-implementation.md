# Projects, typed thought links, and shared project views — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add first-class **projects** (many-to-many with thoughts, ordered membership), **directed typed links** between a user’s thoughts, a **members-only graph** view per project, and **public project shares** (hub, read-all, per-item) with the same token/password/expiry model as `ResearchShare`.

**Architecture:** Normalized tables (`projects`, `project_thought` pivot with composite PK and `sort_order`, `thought_links`, `project_shares`). PHP **backed enum** `ThoughtLinkType` is the single source of truth for link types. **Soft deletes** on `projects`. **Live membership** on public URLs: only thoughts currently in the project are visible. Public flows mirror `SharedResearchViewController` (CommonMark, password cookie `project_share_{token}`, 410 when expired). Owner UI uses **Blade** + `layouts.idea` like the rest of IdeaTub. Graph uses **vis-network** (CDN) fed by a small **JSON** endpoint scoped to project + auth.

**Tech Stack:** Laravel 12, PHP 8.2+, Pest, Blade, Tailwind 4, League CommonMark (same options as `SharedResearchViewController`), vis-network (browser).

**Spec:** `docs/superpowers/specs/2026-04-13-projects-and-thought-links-design.md`

---

## File structure (create / modify)

| Responsibility | Files |
|----------------|--------|
| Schema | `database/migrations/xxxx_create_projects_thought_links_and_shares_tables.php` |
| Enum | `app/Enums/ThoughtLinkType.php` (matches existing `app/Enums/` pattern) |
| Models | `app/Models/Project.php`, `app/Models/ThoughtLink.php`, `app/Models/ProjectShare.php`; modify `app/Models/Thought.php`, `app/Models/User.php` |
| Factories | `database/factories/ProjectFactory.php`, `ThoughtLinkFactory.php`, `ProjectShareFactory.php` |
| Policies | `app/Policies/ProjectPolicy.php`, `app/Policies/ThoughtLinkPolicy.php`, `app/Policies/ProjectSharePolicy.php` |
| Services (optional, small) | `app/Services/ProjectMembershipService.php` — add/remove/reorder with ownership checks (keeps controllers thin) |
| HTTP | `app/Http/Controllers/ProjectController.php`, `ProjectShareController.php`, `ProjectThoughtController.php` (or nested methods on `ProjectController`), `ThoughtLinkController.php`, `SharedProjectViewController.php`, `ProjectGraphController.php` (graph page + `graphData` JSON) |
| Requests | `app/Http/Requests/StoreProjectRequest.php`, `UpdateProjectRequest.php`, `StoreThoughtLinkRequest.php`, `StoreProjectShareRequest.php`, `UpdateProjectShareRequest.php`, `ReorderProjectThoughtsRequest.php` |
| Views | `resources/views/projects/index.blade.php`, `show.blade.php`, `graph.blade.php`, `shares/index.blade.php` (or embed share UI on project show); `resources/views/shared_projects/hub.blade.php`, `read_all.blade.php`, `thought_readonly.blade.php`, `password_form.blade.php` |
| Nav / bootstrap | `resources/views/layouts/idea.blade.php` (or partial): link to `projects.index`; `app/Providers/AppServiceProvider.php` — `RateLimiter::for('project-share-password', …)`; `routes/web.php` |
| Thought detail | `resources/views/idea/show.blade.php` (or partials): project chips, add-to-project, link lists, forms posting to new routes |
| Tests | `tests/Feature/ProjectCrudTest.php`, `ProjectMembershipTest.php`, `ThoughtLinkTest.php`, `SharedProjectViewTest.php`, `ProjectShareManagementTest.php` |

---

## Chunk 1: Schema and domain primitives

### Task 1.1: Single migration for all four tables

**Files:**
- Create: `database/migrations/2026_04_13_100000_create_projects_thought_links_and_shares_tables.php`

- [ ] **Step 1: Add migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('project_thought', function (Blueprint $table) {
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->primary(['project_id', 'thought_id']);
            $table->unique(['project_id', 'sort_order']);
        });

        Schema::create('thought_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('to_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('link_type', 32);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['from_thought_id', 'to_thought_id', 'link_type'], 'thought_links_from_to_type_unique');
        });

        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_shares');
        Schema::dropIfExists('thought_links');
        Schema::dropIfExists('project_thought');
        Schema::dropIfExists('projects');
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: migrated successfully.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_13_100000_create_projects_thought_links_and_shares_tables.php
git commit -m "feat: add projects, pivot, thought_links, project_shares tables"
```

---

### Task 1.2: `ThoughtLinkType` enum

**Files:**
- Create: `app/Enums/ThoughtLinkType.php`

- [ ] **Step 1: Create enum**

```php
<?php

namespace App\Enums;

enum ThoughtLinkType: string
{
    case RelatesTo = 'relates_to';
    case SpawnedFrom = 'spawned_from';
    case Supports = 'supports';
    case Contradicts = 'contradicts';
    case Supersedes = 'supersedes';

}
```

- [ ] **Step 2: Unit test values**

Create `tests/Unit/ThoughtLinkTypeTest.php`:

```php
<?php

use App\Enums\ThoughtLinkType;

test('all spec link types exist', function () {
    expect(ThoughtLinkType::RelatesTo->value)->toBe('relates_to')
        ->and(ThoughtLinkType::SpawnedFrom->value)->toBe('spawned_from')
        ->and(ThoughtLinkType::Supports->value)->toBe('supports')
        ->and(ThoughtLinkType::Contradicts->value)->toBe('contradicts')
        ->and(ThoughtLinkType::Supersedes->value)->toBe('supersedes');
});
```

Run: `php artisan test tests/Unit/ThoughtLinkTypeTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add app/Enums/ThoughtLinkType.php tests/Unit/ThoughtLinkTypeTest.php
git commit -m "feat: add ThoughtLinkType enum"
```

---

### Task 1.3: Eloquent models and relationships

**Files:**
- Create: `app/Models/Project.php`, `app/Models/ThoughtLink.php`, `app/Models/ProjectShare.php`
- Modify: `app/Models/User.php` — `projects()` HasMany; `thoughtLinks()` HasMany
- Modify: `app/Models/Thought.php` — `projects()` BelongsToMany with pivot `sort_order`; `linksFrom()` / `linksTo()` HasMany

**Project model (full):**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['user_id', 'title', 'description'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thoughts(): BelongsToMany
    {
        return $this->belongsToMany(Thought::class, 'project_thought')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ProjectShare::class);
    }
}
```

**ThoughtLink model:** cast nothing special; `link_type` stored as string; validate against enum in Form Request. Fillable: `user_id`, `from_thought_id`, `to_thought_id`, `link_type`, `note`. Relations: `user`, `fromThought`, `toThought`.

**ProjectShare model:** Copy structure from `app/Models/ResearchShare.php` — same `generateToken()`, `isExpired()`, `thought()` becomes `project()`.

- [ ] **Step 1: Write failing feature test for model wiring** (optional lightweight)

`tests/Feature/ProjectModelRelationsTest.php` — create user, project, thought, attach via pivot with sort_order 0, assert `$project->thoughts()->count() === 1`.

- [ ] **Step 2: Implement models + User/Thought relations**

- [ ] **Step 3: Run** `php artisan test tests/Feature/ProjectModelRelationsTest.php`

- [ ] **Step 4: Commit** `feat: add Project, ThoughtLink, ProjectShare models`

---

### Task 1.4: Factories

**Files:**
- Create: `database/factories/ProjectFactory.php`, `ThoughtLinkFactory.php`, `ProjectShareFactory.php`

**ProjectFactory** — set `user_id` from `User::factory()`, `title` fake sentence, `description` nullable.

**ThoughtLinkFactory** — `user_id`, `from_thought_id` / `to_thought_id` from `Thought::factory()` for same user (use afterCreating or state).

**ProjectShareFactory** — `user_id`, `project_id`, `token` via `ProjectShare::generateToken()`.

- [ ] **Step 1: Add factories**
- [ ] **Step 2: Commit** `test: add factories for projects, links, project shares`

---

## Chunk 2: Policies and membership service

### Task 2.1: Policies

**Files:**
- Create: `app/Policies/ProjectPolicy.php`, `ThoughtLinkPolicy.php`, `ProjectSharePolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` or `AuthServiceProvider` if policies are registered manually — Laravel 11+ auto-discovers by convention

**Rules:**
- `ProjectPolicy`: `view`, `update`, `delete` — user id matches `project.user_id`. `create` — authenticated.
- `ThoughtLinkPolicy`: `create`, `delete` — `user_id` on link matches auth id; for `create`, authorize when both thoughts belong to auth user (check in policy using Thought ids from request).
- `ProjectSharePolicy`: same as owning `project` (share’s `user_id` or share’s `project.user_id`).

- [ ] **Step 1: Add policies**
- [ ] **Step 2: Commit** `feat: add policies for projects, thought links, project shares`

---

### Task 2.2: `ProjectMembershipService`

**Files:**
- Create: `app/Services/ProjectMembershipService.php`

**Methods:**
- `addThought(Project $project, Thought $thought): void` — assert `$project->user_id === $thought->user_id`; compute next `sort_order` as `max(sort_order)+1` or 0 if empty; `attach`.
- `removeThought(Project $project, Thought $thought): void` — `detach`; optionally normalize `sort_order` to contiguous 0..n-1.
- `reorder(Project $project, array $orderedThoughtIds): void` — validate set equals current member ids; rewrite `sort_order` 0..n-1 in a transaction.

- [ ] **Step 1: Write `tests/Unit/Services/ProjectMembershipServiceTest.php`** with SQLite in-memory — add two thoughts, reorder, assert pivot order.
- [ ] **Step 2: Implement service**
- [ ] **Step 3:** `php artisan test tests/Unit/Services/ProjectMembershipServiceTest.php`
- [ ] **Step 4: Commit** `feat: add ProjectMembershipService`

---

## Chunk 3: Authenticated project UI

### Task 3.1: `ProjectController` (index, create, store, show, edit, update, destroy)

**Files:**
- Create: `app/Http/Controllers/ProjectController.php`
- Create: `app/Http/Requests/StoreProjectRequest.php` (`title` required string max 255, `description` nullable string)
- Create: `app/Http/Requests/UpdateProjectRequest.php` (same)
- Create: `resources/views/projects/index.blade.php`, `resources/views/projects/show.blade.php`, `resources/views/projects/form.blade.php` (partial)

**Routes (auth group):**

```php
Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
```

Use implicit route model binding; in `Project` model add `resolveRouteBinding` to exclude soft-deleted or use default (soft delete trait adds `whereNull deleted_at` automatically on queries — ensure **only** owner: append `where user_id` via scoped binding or policy in controller).

**Destroy:** soft delete; confirm in form.

- [ ] **Step 1: Feature test `tests/Feature/ProjectCrudTest.php`**

```php
<?php

use App\Models\Project;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest cannot access projects index', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('user can create and list project', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->post(route('projects.store'), ['title' => 'Alpha', 'description' => '# Notes'])
        ->assertRedirect();
    expect(Project::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('user cannot view another users project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);
    $this->actingAs($other)->get(route('projects.show', $project))->assertForbidden();
});
```

- [ ] **Step 2: Implement controller, requests, views** (extend tests for update/delete)
- [ ] **Step 3:** `php artisan test tests/Feature/ProjectCrudTest.php`
- [ ] **Step 4: Commit** `feat: projects CRUD and listing`

---

### Task 3.2: Membership routes and UI

**Files:**
- Create: `app/Http/Controllers/ProjectThoughtController.php` (or methods on `ProjectController`)
- Create: `app/Http/Requests/ReorderProjectThoughtsRequest.php` — `thought_ids` required array of uuid strings
- Modify: `resources/views/projects/show.blade.php` — member list, remove buttons, thought search (reuse pattern from codebase search if exists: e.g. GET `thoughts.search` or inline Select2 — **minimal v1:** text field + submit thought UUID is poor UX; prefer reusing `/api/thoughts/realtime-check` or existing stream search query param pattern from `IdeaController@index` `q=` — implement **dropdown** via fetch to a new `Route::get('/projects/{project}/thought-search', ...)` returning JSON `{ id, snippet }` for current user’s thoughts excluding already members)

**Routes:**

```php
Route::post('/projects/{project}/thoughts', [ProjectThoughtController::class, 'store'])->name('projects.thoughts.store');
Route::delete('/projects/{project}/thoughts/{thought}', [ProjectThoughtController::class, 'destroy'])->name('projects.thoughts.destroy');
Route::put('/projects/{project}/thoughts/reorder', [ProjectThoughtController::class, 'reorder'])->name('projects.thoughts.reorder');
```

- [ ] **Step 1: Feature test `tests/Feature/ProjectMembershipTest.php`** — cannot add other user’s thought; reorder changes pivot order; remove works.
- [ ] **Step 2: Implement**
- [ ] **Step 3: Commit** `feat: project membership add remove reorder`

---

### Task 3.3: Thought detail — projects + links UI

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` — `show` method: eager load `$thought->projects()->where('user_id', auth->id())`; load `linksFrom` and `linksTo` for that thought
- Create: `app/Http/Controllers/ThoughtLinkController.php` — `store`, `destroy`
- Create: `app/Http/Requests/StoreThoughtLinkRequest.php` — validate `to_thought_id` (required, uuid, exists thoughts, same user), `link_type` `Rule::enum(\App\Enums\ThoughtLinkType::class)`, `note` optional; `from` is route param `{thought}`
- Create: partials under `resources/views/idea/partials/` e.g. `thought_projects.blade.php`, `thought_links.blade.php`
- Modify: thought show view to `@include` partials

**Routes:**

```php
Route::post('/thoughts/{thought}/links', [ThoughtLinkController::class, 'store'])->name('thoughts.links.store');
Route::delete('/thoughts/{thought}/links/{thoughtLink}', [ThoughtLinkController::class, 'destroy'])->name('thoughts.links.destroy');
Route::post('/thoughts/{thought}/projects/{project}', ...) // attach
Route::delete('/thoughts/{thought}/projects/{project}', ...) // detach
```

Use policies. Duplicate link unique constraint → catch `QueryException` or validate beforehand; return validation error.

- [ ] **Step 1: Feature test `tests/Feature/ThoughtLinkTest.php`**
- [ ] **Step 2: Implement**
- [ ] **Step 3: Commit** `feat: thought links and project chips on thought detail`

---

## Chunk 4: Graph (members-only)

### Task 4.1: Graph page + JSON

**Files:**
- Create: `app/Http/Controllers/ProjectGraphController.php`
- Create: `resources/views/projects/graph.blade.php` — extends `layouts.idea`, `@push('scripts')` vis-network from `https://unpkg.com/vis-network/standalone/umd/vis-network.min.js`, container div `id="project-graph"`, fetch `route('projects.graph.data', $project)` returns `{ nodes: [{id, label}], edges: [{from, to, label}] }` where edges are `thought_links` with both endpoints in project member set; `label` = link type human label

**Routes:**

```php
Route::get('/projects/{project}/graph', [ProjectGraphController::class, 'show'])->name('projects.graph');
Route::get('/projects/{project}/graph/data', [ProjectGraphController::class, 'data'])->name('projects.graph.data');
```

- [ ] **Step 1: Feature test** — auth user gets 200 JSON with one edge when two members linked
- [ ] **Step 2: Implement**
- [ ] **Step 3: Commit** `feat: project members-only graph view`

---

## Chunk 5: Project shares (owner + public)

### Task 5.1: Rate limiter + public routes

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

```php
RateLimiter::for('project-share-password', function (Request $request) {
    $token = $request->route('token') ?? 'unknown';

    return Limit::perMinutes(15, 10)->by($token.':'.$request->ip());
});
```

- Modify: `routes/web.php` **outside** auth (near research routes):

```php
Route::get('/shared/projects/{token}', [SharedProjectViewController::class, 'hub'])->name('shared-projects.hub');
Route::get('/shared/projects/{token}/read', [SharedProjectViewController::class, 'readAll'])->name('shared-projects.read');
Route::get('/shared/projects/{token}/thoughts/{thought}', [SharedProjectViewController::class, 'thought'])->name('shared-projects.thought');
Route::post('/shared/projects/{token}/unlock', [SharedProjectViewController::class, 'unlock'])
    ->middleware('throttle:project-share-password')
    ->name('shared-projects.unlock');
```

**Password flow note:** Research uses POST to same URL as GET. This plan uses **dedicated POST** `unlock` to avoid three GET paths sharing one POST name — `unlock` verifies password, sets cookie `project_share_{token}`, redirects back to `Referer` or hub. Alternatively mirror research exactly with POST on each of the three routes; **pick one implementation** and use the same cookie check helper in `SharedProjectViewController`.

**SharedProjectViewController** structure (mirror `SharedResearchViewController`):

- Resolve `ProjectShare` by token; 404 if missing; 410 if expired; 404 if `project` soft-deleted or null.
- `password_hash` set → check cookie `project_share_{$token} === $token` before rendering; else show `shared_projects.password_form` posting to `shared-projects.unlock` with `return` query param.
- `hub`: list thoughts ordered by pivot; markdown description via CommonMark.
- `readAll`: foreach thought, convert `content` to HTML (and optionally include comment sections like research — **YAGNI:** v1 render root `content` only unless product insists on parity with research readonly).
- `thought`: verify thought is attached to project via `whereRelation` or `$project->thoughts()->whereKey($thought->id)->exists()`; render readonly single thought.

- [ ] **Step 1: Create `tests/Feature/SharedProjectViewTest.php`** — adapt assertions from `tests/Feature/SharedResearchViewTest.php` for hub, password, expiry, removed member 404, soft-deleted project 404
- [ ] **Step 2: Implement controller + views**
- [ ] **Step 3: Commit** `feat: shared project hub read-all and item views`

---

### Task 5.2: Owner share management

**Files:**
- Create: `app/Http/Controllers/ProjectShareController.php` — `index` (for project), `store`, `update`, `destroy` (mirror `SharedResearchController` fields)
- Create: `app/Http/Requests/StoreProjectShareRequest.php`, `UpdateProjectShareRequest.php`
- Views: section on `projects/show` or dedicated `projects/shares.blade.php`

**Routes:**

```php
Route::get('/projects/{project}/shares', [ProjectShareController::class, 'index'])->name('projects.shares.index');
Route::post('/projects/{project}/shares', [ProjectShareController::class, 'store'])->name('projects.shares.store');
Route::patch('/project-shares/{projectShare}', [ProjectShareController::class, 'update'])->name('project-shares.update');
Route::delete('/project-shares/{projectShare}', [ProjectShareController::class, 'destroy'])->name('project-shares.destroy');
```

Use `Hash::make` for password when present; `password_hash` null when empty on create/update.

- [ ] **Step 1: Feature test `tests/Feature/ProjectShareManagementTest.php`**
- [ ] **Step 2: Implement**
- [ ] **Step 3: Commit** `feat: project share CRUD for owners`

---

## Chunk 6: Navigation, help, polish

### Task 6.1: Nav link and authorization binding

**Files:**
- Modify: `resources/views/layouts/idea.blade.php` — add **Projects** nav item to primary navigation next to Stream / Ideas
- Ensure `Project` route binding scopes to user: override `booted` on `Project` with global scope `where user_id = auth()->id()` **is risky** — instead use `Route::bind` in `RouteServiceProvider` or controller `authorize view`

- [ ] **Step 1: Manual smoke test in browser**
- [ ] **Step 2: Commit** `feat: add Projects to main navigation`

---

### Task 6.2: Help doc

**Files:**
- Modify: `resources/views/help.blade.php` — short bullet: Projects, linking thoughts, sharing project links (link to `/projects`)

- [ ] **Step 1: Commit** `docs: help text for projects and sharing`

---

## Spec coverage (self-review)

| Spec section | Plan tasks |
|--------------|------------|
| §1 Projects + pivot + thought_links + project_shares | Task 1.1, 1.3 |
| §2 Link types enum | Task 1.2 |
| §3 Owner UI index/workspace/graph | Tasks 3.1, 3.2, 4.1, 3.3, 5.2 |
| §4 Public routes | Task 5.1 |
| §5 Authorization | Tasks 2.1, 3.1–3.3, 5.x |
| §6 Testing | Each chunk’s tests |
| §7 MCP deferred | Out of scope |
| §8 Non-goals | Not implemented |
| §9 Implementation notes | vis-network, CommonMark, indexes in migration |

**Placeholder scan:** None intentional; implementers fill exact Blade styling to match existing Tailwind patterns on `shared_research/*` and `idea/*`.

**Type consistency:** `App\Enums\ThoughtLinkType` string values match DB `link_type`; `ProjectShare::generateToken()` matches 64-char pattern; cookie prefix `project_share_`.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-13-projects-thought-links-implementation.md`.

**Two execution options:**

1. **Subagent-driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration. **Required sub-skill:** superpowers:subagent-driven-development.

2. **Inline execution** — Run tasks in this session with superpowers:executing-plans and checkpoints between chunks.

**Which approach do you want?**

# Thought detail — Add to project (header + inline new project) Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move “add thought to project” into the Thought detail header with a disclosure form, support inline **New project…** (title + optional description) in one POST, remove the duplicate form from the Projects panel, harden attach validation (including already-member), and hide mutating project UI when demo mode makes the page non-editable.

**Architecture:** Extend `ThoughtProjectController@store` with two mutually exclusive input modes (`project_id` = real UUID vs literal `__new__`), run attach logic inside `DB::transaction`, reuse `ProjectMembershipService::addThought`. Blade: new partial for the form included from `thought_detail_header` when `editable`; pass `projectsToAttachToThought` from `idea/show`. Alpine `x-data` toggles new-project fields when the select is `__new__`. `<details>/<summary>` for the disclosure.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, Alpine.js (already on `layouts.idea`), Pest, `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-04-13-thought-detail-add-to-project-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/ThoughtProjectController.php` | Dual-mode validation, authorize, transaction, distinct flash messages, `ValidationException` for duplicate membership. |
| `resources/views/idea/show.blade.php` | Pass `projectsToAttachToThought` into `thought_detail_header`; pass `editable` into `thought_detail_projects_and_links`. |
| `resources/views/idea/partials/thought_detail_header.blade.php` | When editable, include `thought_detail_add_to_project` with `thought` + `projectsToAttachToThought`. |
| `resources/views/idea/partials/thought_detail_add_to_project.blade.php` | **New:** `<details>` + POST form, select + Alpine reveal for new project fields. |
| `resources/views/idea/partials/thought_detail_projects_and_links.blade.php` | Remove inline attach form; wrap remove (×) in `@if ($editable ?? true)`. |
| `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php` | Extend: inline create, duplicate member, validation, cross-user forbidden, detail page HTML, demo read-only. |

**Constant:** Sentinel option value `__new__` (string) for “New project…” — document in controller comment; do not use a UUID.

---

## Task 1: Failing feature tests (TDD)

**Files:**
- Modify: `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php`

- [ ] **Step 1: Add tests for inline create, duplicate attach, validation, cross-user, demo**

Append to `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php` (after existing tests, keep `uses(RefreshDatabase::class);`):

```php
use App\Services\DemoMode;

test('user can create project inline when attaching from thought detail', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => '__new__',
            'new_project_title' => 'Fresh project',
            'new_project_description' => 'Body text',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Project created and thought added.');

    $project = Project::query()->where('user_id', $user->id)->where('title', 'Fresh project')->first();
    expect($project)->not->toBeNull()
        ->and($project->description)->toBe('Body text')
        ->and($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();
});

test('attaching thought to project it is already in fails validation', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

    $this->actingAs($user)
        ->from(route('thoughts.show', $thought))
        ->post(route('thoughts.projects.store', $thought), ['project_id' => $project->id])
        ->assertRedirect(route('thoughts.show', $thought))
        ->assertSessionHasErrors('project_id');
});

test('inline attach rejects unknown project id', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => '00000000-0000-0000-0000-000000000099',
        ])
        ->assertSessionHasErrors('project_id');
});

test('inline attach rejects title when not creating new project', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => $project->id,
            'new_project_title' => 'Nope',
        ])
        ->assertSessionHasErrors('new_project_title');
});

test('user cannot attach another users project to their thought', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $other->id]);
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->post(route('thoughts.projects.store', $thought), ['project_id' => $project->id])
        ->assertSessionHasErrors('project_id');
});

test('demo mode thought detail hides add to project and project remove controls', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'ChipTitle']);
    $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

    session([
        DemoMode::ENABLED_SESSION_KEY => true,
        DemoMode::SEED_SESSION_KEY => 'feat-seed-thought-detail-projects-demo',
    ]);

    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertDontSee('Add to project', false)
        ->assertSee('ChipTitle', false);

    session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
});
```

- [ ] **Step 2: Run new tests — expect failures**

Run:

```bash
php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
```

Expected: **FAIL** — e.g. wrong flash message, missing validation, or demo test sees “Add to project” because UI not updated yet.

- [ ] **Step 3: Commit test-only slice (optional but recommended)**

```bash
git add tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
git commit -m "test: cover thought detail add-to-project modes"
```

---

## Task 2: `ThoughtProjectController@store`

**Files:**
- Modify: `app/Http/Controllers/ThoughtProjectController.php`

- [ ] **Step 1: Replace `store` with dual-mode implementation**

Use this implementation (adjust imports at top of file: add `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Validator`, `Illuminate\Validation\Rule`, `Illuminate\Validation\ValidationException`):

```php
public function store(Request $request, Thought $thought, ProjectMembershipService $membership): RedirectResponse
{
    $this->authorize('view', $thought);

    $validated = $request->validate([
        'project_id' => ['required', 'string'],
        'new_project_title' => ['prohibited_unless:project_id,__new__', 'required_if:project_id,__new__', 'string', 'max:255'],
        'new_project_description' => ['prohibited_unless:project_id,__new__', 'nullable', 'string', 'max:65535'],
    ]);

    if ($validated['project_id'] === '__new__') {
        $this->authorize('create', Project::class);

        return DB::transaction(function () use ($request, $thought, $membership, $validated): RedirectResponse {
            $project = Project::create([
                'user_id' => $request->user()->id,
                'title' => $validated['new_project_title'],
                'description' => $validated['new_project_description'] ?? null,
            ]);
            $membership->addThought($project, $thought);

            return back()->with('success', 'Project created and thought added.');
        });
    }

    Validator::make(
        ['project_id' => $validated['project_id']],
        [
            'project_id' => [
                'uuid',
                Rule::exists('projects', 'id')->where('user_id', $request->user()->id),
            ],
        ]
    )->validate();

    $project = Project::query()->findOrFail($validated['project_id']);

    $this->authorize('update', $project);

    if ($project->thoughts()->whereKey($thought->id)->exists()) {
        throw ValidationException::withMessages([
            'project_id' => __('This thought is already in that project.'),
        ]);
    }

    DB::transaction(function () use ($membership, $project, $thought): void {
        $membership->addThought($project, $thought);
    });

    return back()->with('success', 'Added to project.');
}
```

- [ ] **Step 2: Run tests**

```bash
php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
```

Expected: tests for **store** behavior pass; **demo** and **detail page** tests may still fail until Blade is updated.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/ThoughtProjectController.php
git commit -m "feat: thought attach supports inline project creation"
```

---

## Task 3: Blade — header form, show wiring, projects panel

**Files:**
- Create: `resources/views/idea/partials/thought_detail_add_to_project.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_projects_and_links.blade.php`

- [ ] **Step 1: Create `thought_detail_add_to_project.blade.php`**

New file:

```blade
@php
    /** @var \App\Models\Thought $thought */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Project> $projectsToAttachToThought */
@endphp

<details class="mt-4 rounded-xl border border-memory-violet/15 bg-memory-violet/[0.04] px-3 py-2">
    <summary class="cursor-pointer text-sm font-medium text-memory-violet select-none">
        Add to project
    </summary>
    <form
        method="POST"
        action="{{ route('thoughts.projects.store', $thought) }}"
        class="mt-3 space-y-3"
        x-data="{ pick: @js(old('project_id', '')) }"
    >
        @csrf
        <div>
            <label for="attach-project-id-header" class="sr-only">Project</label>
            <select
                id="attach-project-id-header"
                name="project_id"
                required
                x-model="pick"
                class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
            >
                <option value="" disabled @if (! old('project_id')) selected @endif>Choose…</option>
                @foreach ($projectsToAttachToThought as $p)
                    <option value="{{ $p->id }}" @selected(old('project_id') === $p->id)>{{ $p->title }}</option>
                @endforeach
                <option value="__new__" @selected(old('project_id') === '__new__')>New project…</option>
            </select>
        </div>

        <div x-show="pick === '__new__'" x-cloak class="space-y-2">
            <div>
                <label for="new-project-title" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Title</label>
                <input
                    type="text"
                    id="new-project-title"
                    name="new_project_title"
                    value="{{ old('new_project_title') }}"
                    maxlength="255"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                    placeholder="Project name"
                />
            </div>
            <div>
                <label for="new-project-description" class="block text-[10px] uppercase tracking-wider text-slate-brand/60 mb-1">Description <span class="normal-case">(optional)</span></label>
                <textarea
                    id="new-project-description"
                    name="new_project_description"
                    rows="3"
                    class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                    placeholder="Markdown supported">{{ old('new_project_description') }}</textarea>
            </div>
        </div>

        @error('project_id')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('new_project_title')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
        @error('new_project_description')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror

        <button type="submit" class="rounded-lg bg-memory-violet text-white text-sm font-medium px-4 py-2 hover:opacity-90">
            Add to project
        </button>
    </form>
</details>
```

- [ ] **Step 2: Include from `thought_detail_header.blade.php`**

After the opening wrapper and the “Thought detail” label (or after the type-badge row — your choice), pass through when editable. Example immediately after the `<p class="...">Thought detail</p>` block:

```blade
    @if ($editable ?? true)
        @include('idea.partials.thought_detail_add_to_project', [
            'thought' => $thought,
            'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
        ])
    @endif
```

Ensure the header `@include` from `show.blade.php` passes `projectsToAttachToThought`.

- [ ] **Step 3: Update `show.blade.php` includes**

Change the header include to:

```blade
    @include('idea.partials.thought_detail_header', [
        'thought' => $thought,
        'thoughtDetail' => $thoughtDetail,
        'editable' => ! app(\App\Services\DemoMode::class)->enabled(),
        'projectsToAttachToThought' => $projectsToAttachToThought,
    ])
```

Add `editable` to the projects partial include:

```blade
    @include('idea.partials.thought_detail_projects_and_links', [
        'thought' => $thought,
        'thoughtProjectsForDetail' => $thoughtProjectsForDetail,
        'projectsToAttachToThought' => $projectsToAttachToThought,
        'thoughtOutgoingLinks' => $thoughtOutgoingLinks,
        'thoughtIncomingLinks' => $thoughtIncomingLinks,
        'linkTargetThoughtOptions' => $linkTargetThoughtOptions,
        'editable' => ! app(\App\Services\DemoMode::class)->enabled(),
    ])
```

- [ ] **Step 4: Strip attach form from `thought_detail_projects_and_links.blade.php`**

Delete the entire `@if ($projectsToAttachToThought->isNotEmpty())` block that wraps the `<form method="POST" action="{{ route('thoughts.projects.store', $thought) }}">` … `</form>` (lines that currently render the select + Add button).

Wrap the remove button form for each chip in `@if ($editable ?? true)` so demo mode hides the × control:

```blade
@if ($editable ?? true)
    <form method="POST" action="{{ route('thoughts.projects.destroy', [$thought, $p]) }}" class="inline">
        @csrf
        @method('DELETE')
        <button type="submit" class="rounded-full p-1 text-slate-brand/50 hover:text-red-600 hover:bg-red-50" title="Remove from project">×</button>
    </form>
@endif
```

- [ ] **Step 5: Update detail page smoke test**

In `test('thought detail page shows projects and link form', ...)`, add assertions that the add control appears in the page (after Blade change):

```php
    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertSee('Projects', false)
        ->assertSee('Linked thoughts', false)
        ->assertSee('My Project', false)
        ->assertSee('Add to project', false);
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
```

Expected: **PASS** for entire file.

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/partials/thought_detail_add_to_project.blade.php resources/views/idea/partials/thought_detail_header.blade.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_projects_and_links.blade.php tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
git commit -m "feat: thought detail header add-to-project form and demo read-only chips"
```

---

## Plan vs spec (self-review)

| Spec requirement | Task |
|------------------|------|
| Header disclosure + form | Task 3, `thought_detail_add_to_project.blade.php` + header include |
| Select lists non-members + New project… | Task 3 select options |
| Title required / description optional | Task 3 fields + Task 2 validation |
| Remove duplicate form in Projects | Task 3 Step 4 |
| Single POST, transaction, two flashes | Task 2 |
| Already-member error | Task 2 + Task 1 test |
| Demo hides add + remove | Task 3 + demo test |
| Pest coverage | Task 1 + Task 3 Step 5 |

**Placeholder scan:** None intentional; sentinel `__new__` is explicit.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-13-thought-detail-add-to-project.md`. Two execution options:**

1. **Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration. **Required sub-skill:** superpowers:subagent-driven-development.

2. **Inline execution** — Run tasks in this session using executing-plans with checkpoints. **Required sub-skill:** superpowers:executing-plans.

**Which approach do you want?**

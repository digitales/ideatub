# Thought detail — actions row, collapsed linked thoughts, project-scoped link targets

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Share and Add to project into one wrapping actions row after tags; collapse Linked thoughts by default (open on link validation errors); scope link-target options to the union of co-project members when the thought has projects, with fallback + helper copy when that set is empty.

**Architecture:** Extract share link markup into a reusable partial; add `thought_detail_actions_row` that conditionally renders Share and/or Add to project with one top border; tighten `thought_detail_add_to_project` styling when embedded. In `IdeaController@show` (thought detail), build `linkTargetThoughtOptions` with a `whereHas('projects', …)` query when `$memberProjectIds` is non-empty, then fall back to the existing global query and set a boolean for the Blade hint. Wrap the linked-thoughts block in `<details>` with `open` when link-related validation errors exist.

**Tech Stack:** Laravel 12, Blade, Alpine (existing on add-to-project partial), Pest, `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-04-13-thought-detail-actions-row-and-link-scoping-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/IdeaController.php` | Build scoped `linkTargetThoughtOptions`, fallback flag, demo obfuscation unchanged order. |
| `resources/views/idea/partials/thought_detail_document_share_links.blade.php` | **New:** “Share” label + link row only (no outer `border-t` wrapper). |
| `resources/views/idea/partials/thought_detail_document_share.blade.php` | **Modify:** Wrapper with `border-t` + include `thought_detail_document_share_links` (keeps a single place if something includes this partial later). |
| `resources/views/idea/partials/thought_detail_actions_row.blade.php` | **New:** One `border-t` region; flex row with Share block and/or Add to project. |
| `resources/views/idea/partials/thought_detail_add_to_project.blade.php` | **Modify:** Accept `$inActionsRow` (or similar); adjust root `<details>` classes so summary is compact in the row; keep form `w-full` below summary. |
| `resources/views/idea/partials/thought_detail_header.blade.php` | **Modify:** Remove top add-to-project include and bottom document-share include; after tags, `@include` actions row when Share or Add applies. |
| `resources/views/idea/partials/thought_detail_projects_and_links.blade.php` | **Modify:** Wrap Linked thoughts in `<details>`; optional helper paragraph for fallback; `open` when `$errors` has link keys. |
| `resources/views/idea/show.blade.php` | **Modify:** Pass `linkTargetThoughtOptionsUsedGlobalFallback` (or same name) from controller to `thought_detail_projects_and_links` if not already in view data array. |
| `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php` | **Modify:** Extend with link-picker scoping tests; adjust assertions if layout strings change. |

---

### Task 1: Pest tests — link target scoping and fallback

**Files:**
- Modify: `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php`

- [ ] **Step 1: Add failing test — single project restricts picker to co-members**

Append:

```php
test('thought in project sees only co-project thoughts in link target picker', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $current = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNIQUE_CURRENT_BODY']);
    $inProject = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNIQUE_IN_PROJECT_PEER']);
    $outside = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNIQUE_OUTSIDE_PROJECT']);

    $project->thoughts()->attach($current->id, ['sort_order' => 0]);
    $project->thoughts()->attach($inProject->id, ['sort_order' => 1]);

    $html = $this->actingAs($user)
        ->get(route('thoughts.show', $current))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('UNIQUE_IN_PROJECT_PEER')
        ->and($html)->not->toContain('UNIQUE_OUTSIDE_PROJECT');
});
```

- [ ] **Step 2: Add failing test — two projects union**

```php
test('thought in two projects sees link targets in union of both projects', function () {
    $user = User::factory()->create();
    $p1 = Project::factory()->create(['user_id' => $user->id]);
    $p2 = Project::factory()->create(['user_id' => $user->id]);
    $current = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNION_CURRENT']);
    $onlyP1 = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNION_ONLY_P1']);
    $onlyP2 = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNION_ONLY_P2']);
    $neither = Thought::factory()->create(['user_id' => $user->id, 'content' => 'UNION_NEITHER']);

    $p1->thoughts()->attach($current->id, ['sort_order' => 0]);
    $p1->thoughts()->attach($onlyP1->id, ['sort_order' => 1]);
    $p2->thoughts()->attach($current->id, ['sort_order' => 0]);
    $p2->thoughts()->attach($onlyP2->id, ['sort_order' => 1]);

    $html = $this->actingAs($user)
        ->get(route('thoughts.show', $current))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('UNION_ONLY_P1')
        ->and($html)->toContain('UNION_ONLY_P2')
        ->and($html)->not->toContain('UNION_NEITHER');
});
```

- [ ] **Step 3: Add failing test — empty union falls back to global list + helper**

```php
test('sole project member falls back to global link targets with helper copy', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $current = Thought::factory()->create(['user_id' => $user->id, 'content' => 'FALLBACK_CURRENT']);
    $outside = Thought::factory()->create(['user_id' => $user->id, 'content' => 'FALLBACK_OUTSIDE']);

    $project->thoughts()->attach($current->id, ['sort_order' => 0]);

    $response = $this->actingAs($user)
        ->get(route('thoughts.show', $current))
        ->assertOk();

    $response->assertSee('FALLBACK_OUTSIDE', false);
    $response->assertSee('No other thoughts in your project', false);
});
```

- [ ] **Step 4: Run tests — expect failures**

Run: `php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php --filter="co-project|union|FALLBACK"`

Expected: FAIL (scoping and helper not implemented yet).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
git commit -m "test: thought detail link picker project scope (failing)"
```

---

### Task 2: IdeaController — scoped link targets + fallback flag

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (thought `show` method where `linkTargetThoughtOptions` is built)
- Modify: `resources/views/idea/show.blade.php` (pass new variable into `thought_detail_projects_and_links`)

- [ ] **Step 1: Implement query logic**

After `$memberProjectIds = $thoughtProjectsForDetail->pluck('id');`, replace the single `Thought::query()…` block that sets `$linkTargetThoughtOptions` with:

1. A **global** query (same as today): `user_id`, `parent_id` null, `whereKeyNot($thought->id)`, `orderByDesc('updated_at')`, `limit(100)`, `get(['id','content'])`.

2. If `$memberProjectIds->isEmpty()`, set `$linkTargetThoughtOptions` to the global result and `$linkTargetThoughtOptionsUsedGlobalFallback = false`.

3. Else run **scoped** query: same as global but add `->whereHas('projects', fn (Builder $q) => $q->whereIn('projects.id', $memberProjectIds))`. If `isNotEmpty()`, assign to `$linkTargetThoughtOptions` and `$linkTargetThoughtOptionsUsedGlobalFallback = false`. If empty, assign **global** result and `$linkTargetThoughtOptionsUsedGlobalFallback = true`.

4. Keep the existing `if (app(DemoMode::class)->enabled()) { … map obfuscator … }` block applied to the **final** `$linkTargetThoughtOptions` collection (and unchanged handling for incoming/outgoing links).

Add at top of file if missing: `use Illuminate\Database\Eloquent\Builder;`

- [ ] **Step 2: Pass variable to view**

In the same `return view('idea.show', […])` array, add:

`'linkTargetThoughtOptionsUsedGlobalFallback' => $linkTargetThoughtOptionsUsedGlobalFallback,`

Initialize the boolean in all branches so PHP never uses undefined variable.

- [ ] **Step 3: Wire `show.blade.php`**

In `@include('idea.partials.thought_detail_projects_and_links', …)` add:

`'linkTargetThoughtOptionsUsedGlobalFallback' => $linkTargetThoughtOptionsUsedGlobalFallback,`

- [ ] **Step 4: Run scoped tests**

Run: `php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php --filter="co-project|union|FALLBACK"`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/show.blade.php
git commit -m "feat: scope thought link picker to project union with global fallback"
```

---

### Task 3: Blade — share links partial + actions row + header

**Files:**
- Create: `resources/views/idea/partials/thought_detail_document_share_links.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_document_share.blade.php`
- Create: `resources/views/idea/partials/thought_detail_actions_row.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_add_to_project.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`

- [ ] **Step 1: Create `thought_detail_document_share_links.blade.php`**

Move the inner content from `thought_detail_document_share` (the `@php` block can stay in parent or duplicate minimal vars — prefer parent passing `$thought` and `$share`):

Content (equivalent to current lines 6–31 of `thought_detail_document_share.blade.php`):

```blade
@php
    $thought = $thoughtDetail->thought();
    $share = $thoughtDetail->documentShare();
@endphp
<p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Share</p>
<div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px]">
    @if ($share)
        {{-- existing Open link, Copy link, Manage anchors/buttons unchanged --}}
    @else
        {{-- Create share link anchor unchanged --}}
    @endif
</div>
```

(Copy the exact `@if ($share)` branches from the current file — do not abbreviate in real commit.)

- [ ] **Step 2: Slim `thought_detail_document_share.blade.php`**

Keep:

```blade
<div class="mt-4 pt-4 border-t border-memory-violet/10">
    @include('idea.partials.thought_detail_document_share_links', ['thoughtDetail' => $thoughtDetail])
</div>
```

So any direct include of `thought_detail_document_share` still works (defensive).

- [ ] **Step 3: Create `thought_detail_actions_row.blade.php`**

```blade
@php
    $showShare = isset($thoughtDetail) && $thoughtDetail->showDocumentShareBlock();
    $showAdd = $editable ?? true;
@endphp
@if ($showShare || $showAdd)
    <div class="mt-4 pt-4 border-t border-memory-violet/10">
        <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
            @if ($showShare)
                <div class="min-w-0">
                    @include('idea.partials.thought_detail_document_share_links', ['thoughtDetail' => $thoughtDetail])
                </div>
            @endif
            @if ($showAdd)
                @include('idea.partials.thought_detail_add_to_project', [
                    'thought' => $thought,
                    'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
                    'inActionsRow' => true,
                ])
            @endif
        </div>
    </div>
@endif
```

- [ ] **Step 4: Update `thought_detail_add_to_project.blade.php`**

- When `($inActionsRow ?? false)` is true: remove top margin from root `<details>`, drop the heavy bordered “card” look on the closed state; keep `<summary>` as `text-sm font-medium text-memory-violet` (link-like). When false, keep current visual (existing classes) for backward compatibility.
- Ensure the `<form>` has `class="… w-full"` and top margin so when expanded it uses the full content width of the header card.

- [ ] **Step 5: Update `thought_detail_header.blade.php`**

- Delete the block that includes `thought_detail_add_to_project` under the title.
- After the tags `@include`, replace the `@if (showDocumentShareBlock) @include thought_detail_document_share` block with:

```blade
@include('idea.partials.thought_detail_actions_row', [
    'thought' => $thought,
    'thoughtDetail' => $thoughtDetail ?? null,
    'editable' => $editable ?? true,
    'projectsToAttachToThought' => $projectsToAttachToThought ?? collect(),
])
```

Only pass `thoughtDetail` when set (the partial already guards with `isset`).

- [ ] **Step 6: Manual smoke**

Visit a document-eligible thought with projects: Share and Add appear on one band; open Add to project and submit still works.

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/partials/thought_detail_document_share_links.blade.php \
  resources/views/idea/partials/thought_detail_document_share.blade.php \
  resources/views/idea/partials/thought_detail_actions_row.blade.php \
  resources/views/idea/partials/thought_detail_add_to_project.blade.php \
  resources/views/idea/partials/thought_detail_header.blade.php
git commit -m "feat: thought detail actions row for Share and Add to project"
```

---

### Task 4: Blade — collapsed Linked thoughts + fallback hint

**Files:**
- Modify: `resources/views/idea/partials/thought_detail_projects_and_links.blade.php`

- [ ] **Step 1: Wrap Linked thoughts section in `<details>`**

At the start of the Linked thoughts `<section>` (currently line ~28), use:

```blade
@php
    $linkSectionOpen = $errors->has('to_thought_id') || $errors->has('link_type') || $errors->has('note');
@endphp
<details class="group" @if ($linkSectionOpen) open @endif>
    <summary class="cursor-pointer list-none text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3 [&::-webkit-details-marker]:hidden">
        <span class="inline-flex items-center gap-2">
            Linked thoughts
            <span class="text-[10px] font-normal normal-case text-slate-brand/50">(show)</span>
        </span>
    </summary>
    <div class="space-y-4">
        {{-- existing outgoing / incoming / form blocks move here --}}
    </div>
</details>
```

Remove the duplicate standalone `<h2>Linked thoughts</h2>` if the summary replaces it; keep heading hierarchy accessible (summary text = label).

Tune the “(show)” hint or remove if redundant — YAGNI: optional small chevron via CSS if desired.

- [ ] **Step 2: Fallback helper copy**

Immediately above the “New link” `<form>` (inside the details content), when `($linkTargetThoughtOptionsUsedGlobalFallback ?? false)` is true and `$linkTargetThoughtOptions->isNotEmpty()`, render:

```blade
<p class="text-xs text-slate-brand/70 mb-2">No other thoughts in your project(s) yet — showing all thoughts.</p>
```

- [ ] **Step 3: Pass flag from `show.blade.php`**

Ensure `thought_detail_projects_and_links` receives `'linkTargetThoughtOptionsUsedGlobalFallback' => $linkTargetThoughtOptionsUsedGlobalFallback`.

- [ ] **Step 4: Run feature tests**

Run: `php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php`

Expected: PASS (update any test that assumed an `<h2>` string if removed — prefer `assertSee('Linked thoughts', false)` on summary text).

- [ ] **Step 5: Optional — validation keeps section open**

Add test:

```php
test('invalid thought link submission keeps linked thoughts section open', function () {
    $user = User::factory()->create();
    $from = Thought::factory()->create(['user_id' => $user->id]);
    Thought::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('thoughts.show', $from))
        ->post(route('thoughts.links.store', $from), [
            'to_thought_id' => '',
            'link_type' => 'relates_to',
        ])
        ->assertRedirect(route('thoughts.show', $from));

    $this->actingAs($user)
        ->get(route('thoughts.show', $from))
        ->assertOk()
        ->assertSee('<details', false)
        ->assertSee('open', false);
});
```

If `assertSee('open', false)` is too broad, narrow to a `assertStringContainsString('<details class="group" open', $response->getContent())` or similar **after** verifying the attribute order Blade emits.

- [ ] **Step 6: Commit**

```bash
git add resources/views/idea/partials/thought_detail_projects_and_links.blade.php resources/views/idea/show.blade.php tests/Feature/ThoughtLinkAndProjectOnDetailTest.php
git commit -m "feat: collapse linked thoughts by default and show project fallback hint"
```

---

### Task 5: Verification and final commit

- [ ] **Step 1: Run targeted + related tests**

Run:

```bash
php artisan test tests/Feature/ThoughtLinkAndProjectOnDetailTest.php tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php
```

Expected: all PASS.

- [ ] **Step 2: Run Pint on touched PHP**

Run: `./vendor/bin/pint --dirty`

- [ ] **Step 3: Final commit if Pint changed files**

```bash
git add -u && git commit -m "style: pint thought detail link scope changes" || true
```

---

## Self-review (spec coverage)

| Spec section | Task(s) |
|--------------|---------|
| Actions row placement after tags | Task 3, `thought_detail_header` |
| Share + Add same row, wrap | Task 3, `thought_detail_actions_row` |
| Visibility rules (Share / Add / either) | Task 3, `$showShare` / `$showAdd` |
| Remove duplicate Share + top Add | Task 3, header |
| Linked thoughts `<details>` default closed | Task 4 |
| Open on link validation errors | Task 4, `$linkSectionOpen` |
| Union query + limit 100 | Task 2 |
| Empty union → global + helper | Task 2 + Task 4 |
| Demo obfuscation unchanged | Task 2, map after final collection |
| Tests | Task 1, 4, 5 |

**Placeholder scan:** None intentional.

**Type consistency:** Boolean name `linkTargetThoughtOptionsUsedGlobalFallback` used in controller, `show`, and projects/links partial.

---

**Plan complete and saved to `docs/superpowers/plans/2026-04-13-thought-detail-actions-row-and-link-scoping.md`. Two execution options:**

1. **Subagent-Driven (recommended)** — Fresh subagent per task, review between tasks, fast iteration  
2. **Inline execution** — Run tasks in this session using executing-plans with checkpoints  

**Which approach do you want?**

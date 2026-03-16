# Editable thought tags — Implementation plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users edit tags on any thought inline (add, remove, including new tags) on Home, Ideas, and Stream.

**Architecture:** One PATCH endpoint updates `metadata['tags']` on a thought; one shared Blade partial renders the tag row with remove/add and uses Alpine.js + fetch for inline updates. The partial is included in Stream, index thought cards, and Ideas list.

**Tech Stack:** Laravel (Blade, IdeaController), Alpine.js (already in layouts/idea), Tailwind (existing tag pill styles). CSRF via `meta[name="csrf-token"]` (already in layout).

**Spec:** `docs/superpowers/specs/2026-03-16-editable-thought-tags-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `routes/web.php` | Add `PATCH /ideas/{thought}/tags` → `ideas.update-tags` |
| `app/Http/Controllers/IdeaController.php` | Add `updateTags(Request, Thought)`: authorize, validate, normalize+dedupe tags, merge into metadata, return JSON or redirect |
| `resources/views/idea/partials/thought_tag_row.blade.php` | **Create.** Tag row: pills (link + remove when editable), "Add tag" input; Alpine for PATCH and DOM updates |
| `resources/views/idea/stream_thoughts.blade.php` | Replace inline tag block with `@include` of partial |
| `resources/views/idea/index_thought_cards.blade.php` | Replace inline tag block with `@include` of partial |
| `resources/views/idea/ideas.blade.php` | Add tag row partial to each idea card (below content/logged date) |
| `tests/Feature/UpdateThoughtTagsTest.php` | **Create.** Feature tests for PATCH success, validation 422, unauthorized |

---

## Chunk 1: Backend (route, controller, tests)

### Task 1: Route and controller method

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`

- [ ] **Step 1: Add route**

In `routes/web.php`, inside the auth group after the line with `ideas.toggle-completed`, add:

```php
Route::patch('/ideas/{thought}/tags', [IdeaController::class, 'updateTags'])->name('ideas.update-tags');
```

- [ ] **Step 2: Add controller method**

In `app/Http/Controllers/IdeaController.php`, add a new method (e.g. after `toggleCompleted`):

```php
/**
 * Update tags on a thought. Authorizes update; accepts JSON or form.
 */
public function updateTags(Request $request, Thought $thought): RedirectResponse|JsonResponse
{
    $this->authorize('update', $thought);

    $validated = $request->validate([
        'tags' => ['required', 'array'],
        'tags.*' => ['string', 'max:100'],
    ]);

    $tags = array_map(fn ($t) => trim((string) $t), $validated['tags']);
    $normalized = Thought::normalizeMetadataTags(['tags' => $tags]);
    $tags = array_values(array_unique($normalized['tags']));

    $metadata = array_merge($thought->metadata ?? [], ['tags' => $tags]);
    $thought->update(['metadata' => $metadata]);

    if ($request->expectsJson()) {
        return response()->json(['tags' => $tags]);
    }

    return redirect()->back()->with('success', 'Tags updated.');
}
```

- [ ] **Step 3: Commit**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php
git commit -m "feat: add PATCH /ideas/{thought}/tags route and controller"
```

### Task 2: Feature tests

**Files:**
- Create: `tests/Feature/UpdateThoughtTagsTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/UpdateThoughtTagsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateThoughtTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_tags_via_json(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['tags' => ['old']],
        ]);

        $response = $this->actingAs($user)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['plan:test', 'stream'],
        ]);

        $response->assertOk();
        $response->assertJson(['tags' => ['plan:test', 'stream']]);
        $thought->refresh();
        $this->assertSame(['plan:test', 'stream'], $thought->metadata['tags']);
    }

    public function test_tags_are_normalized_and_deduplicated(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [],
        ]);

        $this->actingAs($user)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['  MixedCase  ', 'mixedcase', 'mixedcase'],
        ]);

        $thought->refresh();
        $this->assertSame(['mixedcase'], $thought->metadata['tags']);
    }

    public function test_validation_requires_tags_array(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => 'not-an-array',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('tags');
    }

    public function test_guest_cannot_update_tags(): void
    {
        $thought = Thought::factory()->create();

        $response = $this->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['new'],
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_cannot_update_another_users_thought_tags(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => ['hacked'],
        ]);

        $response->assertForbidden();
    }

    public function test_empty_tags_array_is_allowed(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['tags' => ['old']],
        ]);

        $response = $this->actingAs($user)->patchJson(route('ideas.update-tags', $thought), [
            'tags' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['tags' => []]);
        $thought->refresh();
        $this->assertSame([], $thought->metadata['tags']);
    }
}
```

- [ ] **Step 2: Run tests (expect pass after Task 1)**

Run: `cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/UpdateThoughtTagsTest.php -v`

Expected: All tests pass (route and controller already added in Task 1).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/UpdateThoughtTagsTest.php
git commit -m "test: add UpdateThoughtTags feature tests"
```

---

## Chunk 2: Shared tag row partial and JS

### Task 3: Create thought_tag_row partial

**Files:**
- Create: `resources/views/idea/partials/thought_tag_row.blade.php`

- [ ] **Step 1: Create partial with tags and add control**

Create `resources/views/idea/partials/thought_tag_row.blade.php`:

- Accept `$thought` (required) and `$editable` (optional, default true).
- Define same `$tagColors` and `$tagMap` as in `stream_thoughts.blade.php` so pills look identical.
- For each tag: if editable, wrap in a span that has a remove button (×) and the link to stream; if not editable, just the link.
- When editable: add an input (placeholder "Add tag…") and a small "Add" or "+ Tag" control.
- Wrap the row in an Alpine component that:
  - Has `tags` as reactive state (initial from `$thought->metadata['tags'] ?? []`).
  - Exposes `updateTagsUrl` (route to PATCH) and `thoughtId` for the request.
  - Remove: on click ×, remove tag from local state, PATCH with new list, on failure restore state or show error.
  - Add: on Enter or button click, trim/lowercase the input value, if non-empty and not duplicate append to local state, PATCH, on success clear input, on failure show error.
- Use `fetch` with `X-CSRF-TOKEN: document.querySelector('meta[name="csrf-token"]').content` and `Content-Type: application/json`, body `JSON.stringify({ tags: ... })`.
- Ensure remove/add have accessible labels (e.g. `aria-label="Remove tag X"`, `aria-label="Add tag"`).

Example structure (adapt to your Alpine style):

```blade
@php
    $editable = $editable ?? true;
    $tags = $thought->metadata['tags'] ?? [];
    $tagColors = ['violet', 'teal', 'indigo'];
    $tagMap = [
        'violet' => 'bg-memory-violet/10 text-memory-violet',
        'teal'   => 'bg-neural-teal/10 text-neural-teal',
        'indigo' => 'bg-deep-indigo/8 text-slate-brand',
    ];
@endphp
<div
    class="flex items-center gap-2 flex-wrap"
    x-data="thoughtTagRow({{ json_encode($tags) }}, '{{ e(route('ideas.update-tags', $thought)) }}')"
    x-init="init()"
>
    <template x-for="(tag, index) in tags" :key="index">
        <span class="inline-flex items-center gap-0.5">
            <a :href="'{{ e(route('idea.stream')) }}?tag=' + encodeURIComponent(tag.replace(/\s+/g, '_'))" class="text-[10px] font-medium px-2 py-0.5 rounded-full ..." x-text="'#' + tag"></a>
            @if ($editable)
                <button type="button" @click="remove(index)" aria-label="Remove tag" class="...">×</button>
            @endif
        </span>
    </template>
    @if ($editable)
        <input type="text" x-ref="addInput" placeholder="Add tag…" @keydown.enter="addFromInput()" ...>
        <button type="button" @click="addFromInput()">+ Tag</button>
        <p x-show="error" x-text="error" class="text-xs text-red-500"></p>
    @endif
</div>
```

You must implement the Alpine component `thoughtTagRow(tags, updateUrl)` either in this file (inline `<script>`) or in a shared JS file that is already loaded on idea layout (e.g. in `resources/js/app.js` or an Alpine data file). The component must: `init()` no-op or set tags; `remove(i)` splice tags, PATCH, on 4xx/5xx set error and optionally revert; `addFromInput()` read ref, trim, lowercase, if non-empty and !tags.includes push, PATCH, clear input on success, set error on failure. Use `fetch(updateUrl, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ tags }) })`.

- [ ] **Step 2: Implement Alpine component**

If the project has a global Alpine data registry (e.g. in `app.js`), add `thoughtTagRow(tags, updateUrl)` there. Otherwise add an inline `<script>` in the partial that registers the component (e.g. `document.addEventListener('alpine:init', () => { Alpine.data('thoughtTagRow', (tags, updateUrl) => ({ ... })) })`). Ensure the partial is included in a page that already loads Alpine (idea layout does).

- [ ] **Step 3: Manual check**

Load Stream or Home with a thought that has tags; confirm pills render and link to stream. With editable=true, confirm remove and add trigger PATCH and update the row (no full reload).

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/thought_tag_row.blade.php
# if JS in app.js:
git add resources/js/app.js
git commit -m "feat: add thought_tag_row partial with inline tag edit (Alpine)"
```

---

## Chunk 3: Integrate partial into Stream, Home, Ideas

### Task 4: Stream and index thought cards

**Files:**
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`

- [ ] **Step 1: Stream — use partial**

In `stream_thoughts.blade.php`, remove the `@php` block that sets `$tagColors`, `$tagMap`, and the per-thought `$tags`, and remove the `@foreach ($tags as ...)` tag loop. In the same place (inside the flex div with timestamp and source), add:

```blade
@include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
```

Keep the rest of the card (content, comments) unchanged.

- [ ] **Step 2: Index cards — use partial**

In `index_thought_cards.blade.php`, do the same: remove the tag-related `@php` and the tag `@foreach`; in the same flex div add:

```blade
@include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
```

Preserve Reply link and comments.

- [ ] **Step 3: Commit**

```bash
git add resources/views/idea/stream_thoughts.blade.php resources/views/idea/index_thought_cards.blade.php
git commit -m "feat: use thought_tag_row partial on Stream and Home"
```

### Task 5: Ideas page — show and edit tags

**Files:**
- Modify: `resources/views/idea/ideas.blade.php`

- [ ] **Step 1: Add tag row to each idea card**

In the idea list item (the `<li>` that contains the checkbox, content, logged date, and research block), add the tag row after the logged date line and before the research block. For example, after:

```blade
<p class="text-[11px] text-slate-brand/50 mt-1">{{ $thought->getLoggedDate() }}</p>
```

add:

```blade
@include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/ideas.blade.php
git commit -m "feat: show editable tags on Ideas page"
```

---

## Verification

- [ ] Run full test suite: `php artisan test`
- [ ] Manually: Home — add/remove tags on a thought; Stream — same; Ideas — same. Confirm new tags (e.g. brand-new string) work and appear in Stream when filtering by that tag.
- [ ] Confirm 422 on invalid payload (e.g. `tags` not array) when using JSON.
- [ ] Confirm CSRF is sent (e.g. check Network tab for `X-CSRF-TOKEN` or `_token`).

---

## Plan complete

Plan saved to `docs/superpowers/plans/2026-03-16-editable-thought-tags.md`. Ready to execute?

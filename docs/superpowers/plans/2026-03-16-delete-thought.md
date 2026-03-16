# Delete thought — Implementation plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users delete their own thoughts from the web app (Home and Stream) via a card menu (⋮) and inline confirmation; block delete when the thought has comments.

**Architecture:** One DELETE endpoint on IdeaController with ThoughtPolicy::delete and a comment check returning 422 when comments exist. One shared Blade partial renders the card menu and inline confirm; Alpine.js handles menu/confirm state and fetch(DELETE). Partial included in index thought cards and stream thoughts; stream card div gets data-thought-id for DOM removal.

**Tech Stack:** Laravel (Blade, IdeaController, ThoughtPolicy), Alpine.js (already in app.js/layouts), Tailwind. CSRF via meta name="csrf-token".

**Spec:** `docs/superpowers/specs/2026-03-16-delete-thought-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Policies/ThoughtPolicy.php` | Add `delete(User, Thought): bool` (owner only) |
| `routes/web.php` | Add `DELETE /ideas/{thought}` → `ideas.destroy` |
| `app/Http/Controllers/IdeaController.php` | Add `destroy(Request, Thought)`: authorize, block if comments exist (422), else delete and return 204 or redirect |
| `tests/Feature/DeleteThoughtTest.php` | **Create.** Policy + controller: owner no comments → 204; has comments → 422; wrong user → 403; guest → 401; missing → 404 |
| `resources/views/idea/partials/thought_card_actions.blade.php` | **Create.** Menu (⋮) + inline "Delete thought?" Cancel/Delete; Alpine state; only when editable (owner) |
| `resources/js/app.js` | Add Alpine.data('thoughtCardActions', …) for menu + confirm + DELETE fetch and card removal |
| `resources/views/idea/index_thought_cards.blade.php` | Include thought_card_actions in metadata row (e.g. after Reply); ensure card wrapper has data-thought-id (already has) |
| `resources/views/idea/stream_thoughts.blade.php` | Add data-thought-id to card div; include thought_card_actions in metadata row |

---

## Chunk 1: Backend (policy, route, controller, tests)

### Task 1: Policy and route

**Files:**
- Modify: `app/Policies/ThoughtPolicy.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add delete policy**

In `app/Policies/ThoughtPolicy.php`, add:

```php
/**
 * Whether the user can delete the thought. Only the owner can.
 */
public function delete(User $user, Thought $thought): bool
{
    return $thought->user_id === $user->id;
}
```

- [ ] **Step 2: Add route**

In `routes/web.php`, inside the auth group, after the line with `ideas.update-tags`, add:

```php
Route::delete('/ideas/{thought}', [IdeaController::class, 'destroy'])->name('ideas.destroy');
```

- [ ] **Step 3: Commit**

```bash
git add app/Policies/ThoughtPolicy.php routes/web.php
git commit -m "Add ThoughtPolicy::delete and DELETE /ideas/{thought} route"
```

---

### Task 2: Controller destroy method

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`

- [ ] **Step 1: Add destroy method**

In `app/Http/Controllers/IdeaController.php`, add a new method (e.g. after `updateTags`). Ensure these imports exist at top: `use Illuminate\Http\JsonResponse`, `use Illuminate\Http\RedirectResponse`, `use Illuminate\Http\Request`, `use Symfony\Component\HttpFoundation\Response`.

```php
/**
 * Delete a thought. Owner only; blocked if the thought has comments.
 */
public function destroy(Request $request, Thought $thought): RedirectResponse|JsonResponse
{
    $this->authorize('delete', $thought);

    if ($thought->comments()->exists()) {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(
                ['message' => 'This thought has comments. Remove them first.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return redirect()->back()
            ->with('error', 'This thought has comments. Remove them first.')
            ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $thought->delete();

    if ($request->expectsJson() || $request->ajax()) {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    return redirect()->back()->with('success', 'Thought deleted.');
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "Add IdeaController::destroy with comment check and 204/422"
```

---

### Task 3: Feature tests

**Files:**
- Create: `tests/Feature/DeleteThoughtTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DeleteThoughtTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteThoughtTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_thought_with_no_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'A thought',
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought));

        $response->assertNoContent();
        $this->assertDatabaseMissing('thoughts', ['id' => $thought->id]);
    }

    public function test_owner_cannot_delete_thought_with_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Parent',
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'content' => 'A comment',
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought));

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'This thought has comments. Remove them first.']);
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_other_user_cannot_delete_thought(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'A thought',
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->deleteJson(route('ideas.destroy', $thought));

        $response->assertForbidden();
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_guest_cannot_delete_thought(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'A thought',
            'embedding' => null,
        ]);

        $response = $this->deleteJson(route('ideas.destroy', $thought));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('thoughts', ['id' => $thought->id]);
    }

    public function test_delete_returns_404_for_missing_thought(): void
    {
        $owner = User::factory()->create();
        $uuid = '00000000-0000-0000-0000-000000000001';

        $response = $this->actingAs($owner)->deleteJson(route('ideas.destroy', ['thought' => $uuid]));

        $response->assertNotFound();
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/DeleteThoughtTest.php -v`

Expected: All 5 tests PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DeleteThoughtTest.php
git commit -m "Add feature tests for delete thought (policy and controller)"
```

---

## Chunk 2: Frontend (partial, Alpine, include in views)

### Task 4: Alpine component for delete menu and confirm

**Files:**
- Modify: `resources/js/app.js`

- [ ] **Step 1: Add thoughtCardActions Alpine data**

In `resources/js/app.js`, add a new Alpine.data component (e.g. after `thoughtTagRow`). The component receives `deleteUrl` and `thoughtId`. It must: open/close menu, show/hide inline confirm, send DELETE with CSRF and Accept: application/json, on 204 remove the card (the DOM element that has `data-thought-id` and contains this component—use `this.$el.closest('[data-thought-id]')`), on 422 set error message, on other errors set a generic message. Escape key closes menu or cancels confirm.

Add:

```javascript
Alpine.data('thoughtCardActions', (deleteUrl, thoughtId) => ({
  menuOpen: false,
  confirmOpen: false,
  deleting: false,
  error: '',
  deleteUrl,
  thoughtId,

  get cardEl() {
    return this.$el?.closest('[data-thought-id]') ?? null;
  },

  openMenu() { this.menuOpen = true; this.confirmOpen = false; this.error = ''; },
  closeMenu() { this.menuOpen = false; },
  showConfirm() { this.menuOpen = false; this.confirmOpen = true; this.error = ''; },
  cancelConfirm() { this.confirmOpen = false; this.error = ''; },

  async submitDelete() {
    this.deleting = true;
    this.error = '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    try {
      const res = await fetch(this.deleteUrl, {
        method: 'DELETE',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
      });
      if (res.status === 204) {
        const el = this.cardEl;
        if (el) el.remove();
        return;
      }
      const data = await res.json().catch(() => ({}));
      if (res.status === 422) {
        this.error = data.message || 'This thought has comments. Remove them first.';
        return;
      }
      this.error = 'Couldn’t delete. Try again.';
    } catch {
      this.error = 'Couldn’t delete. Try again.';
    } finally {
      this.deleting = false;
    }
  },

  init() {
    window.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      this.closeMenu();
      this.cancelConfirm();
    });
  },
}));
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/app.js
git commit -m "Add Alpine thoughtCardActions for delete menu and confirm"
```

---

### Task 5: Blade partial for card menu and inline confirm

**Files:**
- Create: `resources/views/idea/partials/thought_card_actions.blade.php`

- [ ] **Step 1: Create partial**

Create `resources/views/idea/partials/thought_card_actions.blade.php`:

```blade
@php
    $editable = $editable ?? (auth()->check() && auth()->id() === $thought->user_id);
@endphp
@if ($editable)
<div
    class="relative ml-auto inline-flex"
    x-data="thoughtCardActions('{{ e(route('ideas.destroy', $thought)) }}', '{{ e($thought->id) }}')"
    @click.outside="closeMenu(); cancelConfirm()"
>
    {{-- Menu button --}}
    <button
        type="button"
        @click="menuOpen = !menuOpen; if (confirmOpen) cancelConfirm()"
        class="p-1 rounded text-slate-brand/50 hover:text-slate-brand hover:bg-slate-brand/5 transition-colors"
        aria-label="Actions"
        aria-haspopup="true"
        :aria-expanded="menuOpen"
    >⋮</button>

    {{-- Dropdown --}}
    <div
        x-show="menuOpen"
        x-transition
        class="absolute right-0 top-full mt-0.5 py-1 min-w-[8rem] rounded-lg border border-memory-violet/15 bg-white shadow-lg z-10"
    >
        <button
            type="button"
            @click="showConfirm()"
            class="w-full text-left px-3 py-1.5 text-[12px] text-red-600 hover:bg-red-50 rounded"
        >Delete</button>
    </div>

    {{-- Inline confirmation --}}
    <div
        x-show="confirmOpen"
        x-transition
        class="absolute right-0 top-full mt-1 p-2 rounded-lg border border-memory-violet/15 bg-white shadow z-10 min-w-[12rem]"
    >
        <p class="text-[12px] text-slate-brand mb-2">Delete thought?</p>
        <p x-show="error" x-text="error" class="text-[11px] text-red-600 mb-2"></p>
        <div class="flex gap-2">
            <button
                type="button"
                @click="cancelConfirm()"
                :disabled="deleting"
                class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
            >Cancel</button>
            <button
                type="button"
                @click="submitDelete()"
                :disabled="deleting"
                class="text-[11px] font-medium text-white px-2 py-1 rounded bg-red-600 hover:bg-red-700 disabled:opacity-50"
            >Delete</button>
        </div>
    </div>
</div>
@endif
```

Note: The partial is wrapped in a `relative` container so the dropdown and confirm are positioned next to the ⋮ button. If the card’s metadata row is flex with gap, this block can sit at the end (e.g. after Reply on index, end of row on stream). The card itself (parent in DOM) must have `data-thought-id="{{ $thought->id }}"` so `cardEl` finds it.

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/partials/thought_card_actions.blade.php
git commit -m "Add thought_card_actions partial (menu + inline delete confirm)"
```

---

### Task 6: Include partial in index thought cards

**Files:**
- Modify: `resources/views/idea/index_thought_cards.blade.php`

- [ ] **Step 1: Add thought_card_actions to metadata row**

In `resources/views/idea/index_thought_cards.blade.php`, inside the `<div class="flex items-center gap-2 flex-wrap">` that contains the tag row and Reply link, add the actions partial after the Reply link (so order is: meta, tags, Reply, menu). Example: after the `@if (!$thought->parent_id)` block that contains the Reply link, add:

```blade
@include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => auth()->check() && auth()->id() === $thought->user_id])
```

Place it inside the same flex div, so the row is: `created_at`, `source`, tag row, Reply (if top-level), then card actions. The card div already has `data-thought-id="{{ $thought->id }}"`.

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/index_thought_cards.blade.php
git commit -m "Include thought_card_actions on index thought cards"
```

---

### Task 7: Include partial in stream thoughts and add data-thought-id

**Files:**
- Modify: `resources/views/idea/stream_thoughts.blade.php`

- [ ] **Step 1: Add data-thought-id to card div and include actions**

In `resources/views/idea/stream_thoughts.blade.php`:
1. On the outer `<div class="rounded-xl border...">` for each thought, add `data-thought-id="{{ $thought->id }}"` so the Alpine component can remove the card on success.
2. Inside the metadata row `<div class="flex items-center gap-2 flex-wrap">`, after the tag row include, add the card actions partial:

```blade
@include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => auth()->check() && auth()->id() === $thought->user_id])
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/stream_thoughts.blade.php
git commit -m "Add data-thought-id and thought_card_actions to stream thought cards"
```

---

### Task 8: Manual verification

- [ ] **Step 1: Run full test suite**

Run: `cd /Users/rosstweedie/Sites/ideatub && php artisan test -v`

Expected: All tests pass (including DeleteThoughtTest).

- [ ] **Step 2: Manual UI check**

In browser: Home and Stream. On a thought you own, open the ⋮ menu → Delete → Cancel (card unchanged). Again → Delete → confirm (card disappears). On a thought with comments, Delete → confirm should show inline “This thought has comments. Remove them first.” and thought remains.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-03-16-delete-thought.md`. Ready to execute?

Use **superpowers:subagent-driven-development** (if subagents available) or **superpowers:executing-plans** to implement. Chunk 1 (backend) can be implemented first and committed; Chunk 2 (frontend) depends on the route and controller being in place.

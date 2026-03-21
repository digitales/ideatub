# Edit Thought Content Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let owners correct typos in saved thought content inline on thought cards without changing tags or other metadata.

**Architecture:** Add a dedicated `PATCH /ideas/{thought}/content` endpoint that updates only the `content` column, then wire an Alpine-powered inline editor into the existing thought-card action menu. Reuse one shared content partial across Home, Stream, and Ideas, while keeping tag editing and delete behavior on separate code paths.

**Tech Stack:** Laravel 12 (routes, controller, Blade, feature tests), Alpine.js in `resources/js/app.js`, Tailwind, Vite, PHPUnit via `php artisan test`.

**Spec:** `docs/superpowers/specs/2026-03-21-edit-thought-content-design.md`

---

### Task 1: Backend route, controller, and feature tests

**Files:**
- Create: `tests/Feature/UpdateThoughtContentTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`

**Step 1: Write the failing test**

Create `tests/Feature/UpdateThoughtContentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateThoughtContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_content_via_json(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Typo tehre',
            'metadata' => ['tags' => ['keep-me'], 'type' => 'idea', 'completed' => false],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Typo there',
        ]);

        $response->assertOk();
        $response->assertJson(['content' => 'Typo there']);

        $thought->refresh();
        $this->assertSame('Typo there', $thought->content);
        $this->assertSame(['keep-me'], $thought->metadata['tags'] ?? null);
        $this->assertSame('idea', $thought->metadata['type'] ?? null);
        $this->assertFalse($thought->metadata['completed'] ?? true);
    }

    public function test_validation_rejects_empty_or_whitespace_only_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Original content',
            'metadata' => ['tags' => ['keep-me']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
            'content' => '   ',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('content');

        $thought->refresh();
        $this->assertSame('Original content', $thought->content);
        $this->assertSame(['keep-me'], $thought->metadata['tags'] ?? null);
    }

    public function test_guest_cannot_update_content(): void
    {
        $thought = Thought::factory()->create([
            'content' => 'Original content',
            'embedding' => null,
        ]);

        $response = $this->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Changed content',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_cannot_update_another_users_thought_content(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Owner content',
            'metadata' => ['tags' => ['original']],
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->patchJson(route('ideas.update-content', $thought), [
            'content' => 'Hacked content',
        ]);

        $response->assertForbidden();

        $thought->refresh();
        $this->assertSame('Owner content', $thought->content);
        $this->assertSame(['original'], $thought->metadata['tags'] ?? null);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/UpdateThoughtContentTest.php -v`

Expected: FAIL because `ideas.update-content` does not exist yet.

**Step 3: Write minimal implementation**

In `routes/web.php`, add the new route directly after `ideas.update-tags`:

```php
Route::patch('/ideas/{thought}/content', [IdeaController::class, 'updateContent'])->name('ideas.update-content');
```

In `app/Http/Controllers/IdeaController.php`, add the controller method near `updateTags()`:

```php
/**
 * Update thought content only. Tags and metadata stay untouched.
 */
public function updateContent(Request $request, Thought $thought): RedirectResponse|JsonResponse
{
    $this->authorize('update', $thought);

    $validated = $request->validate([
        'content' => ['required', 'string', 'max:65535'],
    ]);

    $content = trim($validated['content']);
    if ($content === '') {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'content' => 'Content cannot be empty.',
        ]);
    }

    $thought->update(['content' => $content]);

    if ($request->expectsJson() || $request->ajax()) {
        return response()->json(['content' => $thought->content]);
    }

    return redirect()->back()->with('success', 'Thought updated.');
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/UpdateThoughtContentTest.php -v`

Expected: PASS. Content changes, tags stay the same, and auth/validation branches behave correctly.

**Step 5: Commit**

```bash
git add tests/Feature/UpdateThoughtContentTest.php routes/web.php app/Http/Controllers/IdeaController.php
git commit -m "feat: add thought content update endpoint"
```

---

### Task 2: Shared inline editor component and action-menu hook

**Files:**
- Create: `resources/views/idea/partials/editable_thought_content.blade.php`
- Modify: `resources/views/idea/partials/thought_card_actions.blade.php`
- Modify: `resources/js/app.js`

**Step 1: Write the failing UI assertions**

Add one focused assertion to each existing page test file before wiring the UI:

- In `tests/Feature/IdeaPageTest.php`, add a test that a stored thought row contains `Edit` and the `ideas.update-content` URL for the owner.
- In `tests/Feature/StreamPageTest.php`, add a test that a stream card contains `Edit`.

Use simple HTML assertions like:

```php
$response = $this->actingAs($user)->get(route('idea.index'));
$response->assertSee('Edit');
$response->assertSee(route('ideas.update-content', $thought), false);
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v`

Expected: FAIL because the cards do not render any content-edit action yet.

**Step 3: Write minimal implementation**

Create `resources/views/idea/partials/editable_thought_content.blade.php`:

```blade
@php
    $editable = $editable ?? (auth()->check() && auth()->id() === $thought->user_id);
    $displayClass = $displayClass ?? 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line';
    $editorClass = $editorClass ?? 'w-full text-[13.5px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20';
@endphp

<div
    x-data="thoughtContentEditor({
        content: @js($thought->content),
        updateUrl: @js(route('ideas.update-content', $thought)),
        editable: @js($editable),
    })"
    x-on:thought-edit-requested.window="if ($event.detail?.thoughtId === @js((string) $thought->id)) startEdit()"
>
    <template x-if="!editing">
        <p class="{{ $displayClass }}" x-text="content"></p>
    </template>

    <template x-if="editing">
        <div class="mb-2">
            <textarea x-model="draftContent" rows="4" class="{{ $editorClass }}"></textarea>
            <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
            <div class="flex items-center gap-2 mt-2">
                <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
            </div>
        </div>
    </template>
</div>
```

In `resources/js/app.js`, add an Alpine component before `Alpine.start()`:

```js
Alpine.data('thoughtContentEditor', ({ content, updateUrl, editable = false }) => ({
  content: content || '',
  originalContent: content || '',
  draftContent: content || '',
  updateUrl: updateUrl || '',
  editable: !!editable,
  editing: false,
  saving: false,
  error: '',

  get saveDisabled() {
    return this.saving || this.draftContent.trim() === '' || this.draftContent === this.originalContent;
  },

  startEdit() {
    if (!this.editable) return;
    this.editing = true;
    this.draftContent = this.content;
    this.error = '';
    this.$nextTick(() => this.$el.querySelector('textarea')?.focus());
  },

  cancelEdit() {
    this.editing = false;
    this.draftContent = this.content;
    this.error = '';
  },

  async saveEdit() {
    const trimmed = this.draftContent.trim();
    if (!trimmed || this.saveDisabled) return;

    this.saving = true;
    this.error = '';

    try {
      const res = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ content: trimmed }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        if (res.status === 422 && data.errors?.content?.[0]) this.error = data.errors.content[0];
        else if (res.status === 401 || res.status === 403 || res.status === 419) this.error = 'Please sign in again.';
        else if (res.status === 404) this.error = 'This thought no longer exists.';
        else this.error = data.message || 'Unable to update thought.';
        return;
      }

      this.content = data.content ?? trimmed;
      this.originalContent = this.content;
      this.draftContent = this.content;
      this.editing = false;
    } catch {
      this.error = 'Unable to update thought.';
    } finally {
      this.saving = false;
    }
  },

  init() {
    const handler = (e) => {
      if (e.key === 'Escape' && this.editing) {
        e.preventDefault();
        this.cancelEdit();
      }
    };
    window.addEventListener('keydown', handler);
  },
}));
```

In `resources/views/idea/partials/thought_card_actions.blade.php`, add **Edit** above **Delete**:

```blade
<button
    type="button"
    @click="requestEdit()"
    class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded"
>Edit</button>
```

And in `thoughtCardActions()` add:

```js
requestEdit() {
  this.menuOpen = false;
  this.confirmOpen = false;
  this.error = '';
  window.dispatchEvent(new CustomEvent('thought-edit-requested', {
    detail: { thoughtId: this.thoughtId },
  }));
},
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v`

Expected: PASS. Owner pages render the edit affordance and content-update route.

**Step 5: Commit**

```bash
git add resources/views/idea/partials/editable_thought_content.blade.php resources/views/idea/partials/thought_card_actions.blade.php resources/js/app.js tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
git commit -m "feat: add shared inline thought content editor"
```

---

### Task 3: Integrate the shared editor into Home and Stream card bodies

**Files:**
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`

**Step 1: Write the failing assertions**

Extend the same page tests to assert the cards now render the shared editor container instead of only plain text. Use an assertion against the update route or Alpine hook in the card HTML:

```php
$response->assertSee('thought-edit-requested', false);
$response->assertSee(route('ideas.update-content', $thought), false);
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v`

Expected: FAIL because the body still renders a plain `<p>{{ $thought->content }}</p>`.

**Step 3: Write minimal implementation**

In `resources/views/idea/index_thought_cards.blade.php`, replace:

```blade
<p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line">{{ $thought->content }}</p>
```

with:

```blade
@include('idea.partials.editable_thought_content', [
    'thought' => $thought,
    'editable' => auth()->check() && auth()->id() === $thought->user_id,
    'displayClass' => 'text-[13.5px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line',
])
```

Do the same in `resources/views/idea/stream_thoughts.blade.php` with the same `displayClass`.

Do not modify the tag row include. The goal is for content editing and tag editing to remain separate neighbors in the card.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v`

Expected: PASS. Home and Stream render the shared editor path while continuing to show existing metadata, tags, and comments.

**Step 5: Commit**

```bash
git add resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php
git commit -m "feat: enable inline thought editing on home and stream"
```

---

### Task 4: Add Ideas-page root wiring, actions menu, and full-content editing

**Files:**
- Modify: `resources/views/idea/partials/ideas_list.blade.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`

**Step 1: Write the failing test**

In `tests/Feature/IdeaIdeasTest.php`, add a test that proves the Ideas list renders edit controls for the owner and keeps access to full content even though the visible view is truncated:

```php
public function test_ideas_page_renders_edit_action_and_full_content_payload(): void
{
    $user = User::factory()->create();
    $full = 'Start '.str_repeat('Long idea text ', 30).' End';

    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => $full,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->get(route('idea.ideas'));

    $response->assertOk();
    $response->assertSee('Edit');
    $response->assertSee(route('ideas.update-content', $thought), false);
    $response->assertSee(e($full), false);
}
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php -v`

Expected: FAIL because Ideas rows do not yet include `thought_card_actions`, card-root wiring, or the inline editor partial.

**Step 3: Write minimal implementation**

In `resources/views/idea/partials/ideas_list.blade.php`:

1. Add root wiring to the `<li>`:

```blade
<li
    data-thought-id="{{ $thought->id }}"
    class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 flex items-start gap-3"
>
```

2. Add the shared actions menu near the top-right of the row:

```blade
<div class="absolute top-3 right-3">
    @include('idea.partials.thought_card_actions', [
        'thought' => $thought,
        'editable' => auth()->check() && auth()->id() === $thought->user_id,
    ])
</div>
```

3. Give the content column right padding so the menu has room:

```blade
<div class="min-w-0 flex-1 pr-8">
```

4. Replace the truncated `<p>` with the shared partial, but preserve truncated view mode by passing a truncated display string separately if needed. The simplest version is:

```blade
@include('idea.partials.editable_thought_content', [
    'thought' => $thought,
    'editable' => auth()->check() && auth()->id() === $thought->user_id,
    'displayClass' => 'text-sm text-deep-indigo '.($thought->isIdeaCompleted() ? 'line-through text-slate-brand/70' : ''),
    'editorClass' => 'w-full text-sm text-deep-indigo rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20',
])
```

5. If you want truncated display mode while not editing, extend the partial with an optional `displayText` prop and pass:

```blade
'displayText' => Str::limit($thought->content, 200),
```

while still keeping the full `thought->content` as the editor source and PATCH payload.

Do not add edit controls to the inline research preview block under each idea. That block remains out of scope in v1.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php -v`

Expected: PASS. Ideas rows now have the same edit affordance and content-update URL, and the editor still has access to full stored content.

**Step 5: Commit**

```bash
git add resources/views/idea/partials/ideas_list.blade.php tests/Feature/IdeaIdeasTest.php
git commit -m "feat: enable inline thought editing on ideas page"
```

---

### Task 5: Verification and cleanup

**Files:**
- Modify: only if verification reveals issues

**Step 1: Run targeted tests**

Run: `php artisan test tests/Feature/UpdateThoughtContentTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/IdeaIdeasTest.php -v`

Expected: PASS.

**Step 2: Run broader regression suite**

Run: `php artisan test tests/Feature/UpdateThoughtTagsTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/IdeaIdeasTest.php -v`

Expected: PASS. Content editing should not break tag editing or existing page rendering.

**Step 3: Build frontend assets**

Run: `npm run build`

Expected: PASS with Vite output and no Alpine syntax errors.

**Step 4: Manual verification**

Check these flows in the browser:

- Home: owner opens `Edit`, fixes a typo, saves, sees updated text without a page reload.
- Stream: same behavior, with tags still present and unchanged.
- Ideas: visible card may be truncated before edit, but entering edit mode shows the full stored content.
- Cancel exits edit mode without saving.
- Save button stays disabled until content changes.
- Editing does not remove or rewrite tags.
- Nested comment snippets inside parent cards do not suddenly show edit controls.

**Step 5: Commit any verification fixes**

```bash
git add .
git commit -m "fix: polish inline thought content editing"
```

---

Plan complete and saved to `docs/plans/2026-03-21-edit-thought-content.md`. Two execution options:

**1. Subagent-Driven (this session)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Parallel Session (separate)** - Open a new session with executing-plans, batch execution with checkpoints

Which approach?

# Thought drafts, auto-save, and focus mode — Implementation plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add server-side drafts for the thought capture form (cap 10 per user), debounced auto-save, a draft list (shown only when drafts exist), and a full-window focus overlay; close via Escape, Close button, or backdrop click.

**Architecture:** New `drafts` table and `Draft` model; `DraftController` with JSON API (list, create, show, update, delete) under auth; extend `captureBox()` Alpine component for drafts fetch, debounced auto-save, draft list UI, and focus overlay; after successful "Store thought", DELETE bound draft. Draft list and expand control only when not in reply mode; draft list visible only when `drafts.length > 0`.

**Tech Stack:** Laravel (migration, Draft model, DraftController, routes), Blade (data attributes for draft API URL), Alpine.js (captureBox extension), Tailwind, CSRF via meta tag.

**Spec:** `docs/superpowers/specs/2026-03-17-thought-drafts-and-focus-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_drafts_table.php` | Create `drafts` table: id (uuid), user_id, content (text), no_chunking (bool), updated_at; index (user_id, updated_at) |
| `app/Models/Draft.php` | **Create.** Eloquent model, HasUuids, fillable, user relation, scope for cap enforcement |
| `app/Http/Controllers/DraftController.php` | **Create.** index (list), store (create + cap), show, update, destroy; JSON only; authorize user |
| `routes/web.php` | Add GET/POST `ideas/drafts`, GET/PATCH/DELETE `ideas/drafts/{draft}` inside auth group |
| `tests/Feature/DraftControllerTest.php` | **Create.** List (empty, with drafts), create (cap), show, update, destroy; 401 guest, 403 other user's draft |
| `resources/js/app.js` | Extend `captureBox()`: drafts array, currentDraftId, fetchDrafts, debounced saveDraft, loadDraft (Resume), discardDraft, focusOverlayOpen, toggleFocus, focus trap, backdrop click |
| `resources/views/idea/index.blade.php` | Add data-drafts-url, draft list markup (x-show when drafts.length), Expand/Focus button, focus overlay wrapper (backdrop + dialog), pass replying flag for no-draft logic |

---

## Chunk 1: Backend (migration, model, controller, routes, tests)

### Task 1: Migration and Draft model

**Files:**
- Create: `database/migrations/2026_03_17_000001_create_drafts_table.php`
- Create: `app/Models/Draft.php`

- [ ] **Step 1: Create migration**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan make:migration create_drafts_table
```

Then edit the created migration file (exact path will be under `database/migrations/` with timestamp). Replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->boolean('no_chunking')->default(false);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: Migration table created, `drafts` table exists.

- [ ] **Step 3: Create Draft model**

Create `app/Models/Draft.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = ['content', 'no_chunking'];

    protected $casts = [
        'no_chunking' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Preview for list: first 60 chars or first line.
     */
    public function getContentPreviewAttribute(): string
    {
        $text = $this->content;
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        if (mb_strlen($firstLine) <= 60) {
            return $firstLine ?: mb_substr($text, 0, 60);
        }
        return mb_substr($firstLine, 0, 57) . '...';
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_drafts_table.php app/Models/Draft.php
git commit -m "Add drafts table and Draft model"
```

---

### Task 2: DraftController and routes

**Files:**
- Create: `app/Http/Controllers/DraftController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add routes**

In `routes/web.php`, inside the `Route::middleware('auth')->group`, after the line with `ideas.research` (the last ideas route), add:

```php
    // Drafts for thought capture (list, create, show, update, delete)
    Route::get('/ideas/drafts', [App\Http\Controllers\DraftController::class, 'index'])->name('ideas.drafts.index');
    Route::post('/ideas/drafts', [App\Http\Controllers\DraftController::class, 'store'])->name('ideas.drafts.store');
    Route::get('/ideas/drafts/{draft}', [App\Http\Controllers\DraftController::class, 'show'])->name('ideas.drafts.show');
    Route::patch('/ideas/drafts/{draft}', [App\Http\Controllers\DraftController::class, 'update'])->name('ideas.drafts.update');
    Route::delete('/ideas/drafts/{draft}', [App\Http\Controllers\DraftController::class, 'destroy'])->name('ideas.drafts.destroy');
```

Add at top of `routes/web.php` with other use statements:

```php
use App\Http\Controllers\DraftController;
```

(If you prefer to keep the file minimal, you can use the full class name in the route array and omit the use statement.)

- [ ] **Step 2: Create DraftController**

Create `app/Http/Controllers/DraftController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DraftController extends Controller
{
    private const CAP = 10;

    /**
     * List current user's drafts, ordered by updated_at desc. JSON only.
     */
    public function index(Request $request): JsonResponse
    {
        $drafts = Draft::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(self::CAP)
            ->get()
            ->map(fn (Draft $d) => [
                'id' => $d->id,
                'content_preview' => $d->content_preview,
                'updated_at' => $d->updated_at->toIso8601String(),
                'updated_at_human' => $d->updated_at->diffForHumans(),
            ]);

        return response()->json($drafts);
    }

    /**
     * Create a draft. Enforce cap by deleting oldest first. JSON only.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'no_chunking' => 'sometimes|boolean',
        ]);

        $userId = $request->user()->id;
        $count = Draft::where('user_id', $userId)->count();
        if ($count >= self::CAP) {
            Draft::where('user_id', $userId)
                ->orderBy('updated_at')
                ->limit($count - self::CAP + 1)
                ->delete();
        }

        $draft = Draft::create([
            'user_id' => $userId,
            'content' => $validated['content'],
            'no_chunking' => (bool) ($validated['no_chunking'] ?? false),
        ]);

        return response()->json($this->draftResponse($draft), Response::HTTP_CREATED);
    }

    /**
     * Get one draft for resume. 404 if not found or not owner. JSON only.
     */
    public function show(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json($this->draftResponse($draft));
    }

    /**
     * Update draft (auto-save). 404 if not owner. JSON only.
     */
    public function update(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'no_chunking' => 'sometimes|boolean',
        ]);

        $draft->update([
            'content' => $validated['content'],
            'no_chunking' => (bool) ($validated['no_chunking'] ?? $draft->no_chunking),
        ]);

        return response()->json($this->draftResponse($draft));
    }

    /**
     * Delete draft. 404 if not owner. 204. JSON only.
     */
    public function destroy(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        $draft->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function draftResponse(Draft $draft): array
    {
        return [
            'id' => $draft->id,
            'content' => $draft->content,
            'no_chunking' => $draft->no_chunking,
            'updated_at' => $draft->updated_at->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/DraftController.php routes/web.php
git commit -m "Add DraftController and draft API routes"
```

---

### Task 3: Feature tests for DraftController

**Files:**
- Create: `tests/Feature/DraftControllerTest.php`

- [ ] **Step 1: Write feature tests**

Create `tests/Feature/DraftControllerTest.php` using the project's test base (e.g. `Tests\TestCase`, `Illuminate\Foundation\Testing\RefreshDatabase`). Cover:

1. **index:** Guest gets 401. Authenticated user with no drafts gets `[]`. User with drafts gets array of `id`, `content_preview`, `updated_at` (and optionally `updated_at_human`), max 10.
2. **store:** Guest 401. Valid body creates draft, returns 201 and draft payload. Cap: creating 11th draft deletes oldest, still returns 201.
3. **show:** Guest 401. Owner gets 200 and full draft. Other user gets 404. Invalid uuid gets 404.
4. **update:** Guest 401. Owner can PATCH, returns 200 and updated draft. Other user 404.
5. **destroy:** Guest 401. Owner can DELETE, returns 204. Other user 404.

Use `User::factory()`, `Draft::create([...])` as needed. Example structure (adjust to match project style):

```php
<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $response = $this->getJson(route('ideas.drafts.index'));
        $response->assertStatus(401);
    }

    public function test_index_returns_empty_when_no_drafts(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk()->assertExactJson([]);
    }

    public function test_index_returns_drafts_for_user(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create([
            'user_id' => $user->id,
            'content' => 'Hello world',
            'no_chunking' => false,
        ]);
        $response = $this->actingAs($user)->getJson(route('ideas.drafts.index'));
        $response->assertOk();
        $json = $response->json();
        $this->assertCount(1, $json);
        $this->assertSame($draft->id, $json[0]['id']);
        $this->assertArrayHasKey('content_preview', $json[0]);
        $this->assertArrayHasKey('updated_at', $json[0]);
    }

    public function test_store_requires_auth(): void
    {
        $response = $this->postJson(route('ideas.drafts.store'), ['content' => 'x']);
        $response->assertStatus(401);
    }

    public function test_store_creates_draft(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson(route('ideas.drafts.store'), [
            'content' => 'My draft',
            'no_chunking' => true,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('content', 'My draft');
        $response->assertJsonPath('no_chunking', true);
        $this->assertDatabaseHas('drafts', ['user_id' => $user->id, 'content' => 'My draft']);
    }

    public function test_show_returns_404_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $draft = Draft::create(['user_id' => $owner->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($other)->getJson(route('ideas.drafts.show', $draft));
        $response->assertStatus(404);
    }

    public function test_update_owner_succeeds(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'old', 'no_chunking' => false]);
        $response = $this->actingAs($user)->patchJson(route('ideas.drafts.update', $draft), [
            'content' => 'new',
            'no_chunking' => true,
        ]);
        $response->assertOk()->assertJsonPath('content', 'new');
        $draft->refresh();
        $this->assertSame('new', $draft->content);
        $this->assertTrue($draft->no_chunking);
    }

    public function test_destroy_owner_returns_204(): void
    {
        $user = User::factory()->create();
        $draft = Draft::create(['user_id' => $user->id, 'content' => 'x', 'no_chunking' => false]);
        $response = $this->actingAs($user)->deleteJson(route('ideas.drafts.destroy', $draft));
        $response->assertStatus(204);
        $this->assertDatabaseMissing('drafts', ['id' => $draft->id]);
    }
}
```

Add at least one test for cap (create 11 drafts, assert only 10 exist and list returns 10). Omit `DB` import if unused.

- [ ] **Step 2: Run tests**

```bash
php artisan test tests/Feature/DraftControllerTest.php
```

Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DraftControllerTest.php
git commit -m "Add DraftController feature tests"
```

---

## Chunk 2: Store thought deletes bound draft

Before changing the front end, the backend must support deleting a draft when a thought is stored. The spec says: after successful "Store thought", the client will call DELETE on the bound draft. No backend change is required for that—the existing DELETE route is used. Optionally, the backend could accept a `draft_id` on the store request and delete it server-side; the spec leaves this to the client, so we implement client-side DELETE after store success.

No separate task; covered in Chunk 3 when extending `submitCapture()`.

---

## Chunk 3: Frontend — drafts API, auto-save, draft list, focus overlay

### Task 4: Blade — data attribute and draft list / Expand / overlay markup

**Files:**
- Modify: `resources/views/idea/index.blade.php`

- [ ] **Step 1: Add drafts URL and reply flag to capture box**

On the capture box `div` (the one with `x-data="captureBox()"`), add:

- `data-drafts-url="{{ route('ideas.drafts.index') }}"` (no trailing slash; frontend will append `/{id}` for show/update/destroy).
- Ensure `data-focus-reply` is present (already is): when `1`, frontend will not fetch drafts, not auto-save, and not show draft list.

- [ ] **Step 2: Add draft list UI (only when drafts exist)**

Inside the capture box div, above the form (e.g. above the AJAX message or between message and form), add a block that is shown only when there are drafts and not in reply mode. Use Alpine state: e.g. `x-show="drafts.length > 0 && !isReplyMode"` with `x-cloak` and a container that lists drafts. Each draft: preview text, `updated_at_human`, "Resume" button, "Discard" button. Use `x-transition` if you want. Collapsed by default: e.g. a line "Drafts (n)" that toggles `draftsExpanded` to show/hide the list. Structure:

- A wrapper `template` or `div` with `x-show="drafts.length > 0 && !isReplyMode"`.
- Inner: a button or link "Drafts (<span x-text="drafts.length">)" that toggles `draftsExpanded`.
- A list `x-show="draftsExpanded"` with `template x-for="draft in drafts" :key="draft.id"` (or iterate and render each row with preview, updated_at_human, Resume, Discard). Resume calls `loadDraft(draft.id)`; Discard calls `discardDraft(draft.id)` and removes from local `drafts` (and optionally confirm for non-empty).

- [ ] **Step 3: Add Expand / Focus button**

In the capture box footer row (next to "⌘ + Enter to store"), add a button or link "Focus" (or "Expand") that calls `toggleFocus()` or sets `focusOverlayOpen = true`. It should be visible when not in reply mode (or visible in reply mode too per spec; draft list stays hidden when replying).

- [ ] **Step 4: Add focus overlay**

Wrap the capture form (and draft list) in a structure that can be shown either inline or inside the overlay. Option A: duplicate the capture content into an overlay div that is shown when `focusOverlayOpen` (same Alpine state). Option B: use one capture content and move it into the overlay when expanded (trickier with Alpine). Option C: keep one capture box; when focus mode is on, add a full-screen overlay that contains a copy of the same form bound to the same Alpine state—but Alpine doesn't support one state in two places. Recommended: single capture box lives inside a wrapper. When `focusOverlayOpen` is true, the same wrapper content is shown in a fixed overlay (clone or teleport). Simplest: the capture box div is always in the page; when `focusOverlayOpen`, add a second layer—a `div` with `fixed inset-0 z-50` that has `role="dialog"` and `aria-modal="true"`, a dimmed backdrop, and inside it a copy of the form + draft list that shares no state—that would duplicate state. So the clean approach is: the capture box is the single source of truth. When "Focus" is clicked, show a full-screen overlay that contains only a backdrop and a panel; the panel does not duplicate the form. Instead, use CSS to make the existing capture box move into the overlay (e.g. teleport the DOM node) or keep the capture box in place but visually expand it to full screen. Easiest: when `focusOverlayOpen`, render a full-screen overlay with `position: fixed; inset: 0; z-index: 50`, backdrop (click to close), and a centered container that has the same form fields and draft list, and bind those to the same Alpine component state. That requires the form to exist in two places in the DOM with the same `x-data="captureBox()"`—Alpine will create two component instances. So we must have a single form in the DOM and either (1) move it into the overlay when open (JavaScript move the node), or (2) have the overlay contain the only form and the "inline" view is a placeholder or summary. Spec says "Same form, same state". So: one form, one Alpine component. When focus opens, we need that single form to appear inside the overlay. Use a wrapper: `<div x-data="captureBox()" ...>` contains `<div x-show="!focusOverlayOpen">` (inline placeholder: "Focus" button and maybe a one-line summary) and `<div x-show="focusOverlayOpen" class="fixed inset-0 z-50 ..." role="dialog" aria-modal="true">` (backdrop + centered panel with the actual form and draft list). So the form and textarea live inside the overlay; when `focusOverlayOpen` is false, we hide the overlay and show an "inline" view. But then the inline view has no form—only the overlay has the form. So the page would show: [ Hero ] [ Inline: "Focus" button only ] [ Thoughts list ]. When you click Focus, overlay opens with the form. When you close, overlay closes and you see the inline "Focus" button again; the form is in the overlay and hidden. That works: one form, one state, form is in the overlay. Inline view is just a call-to-action to open Focus. But then when not in focus mode, the user has no way to type without opening Focus. So we need both: inline form and overlay form. The only way to have one form and one state is to have one DOM form that is either in the inline slot or in the overlay slot. So we need a single form that is moved (teleported) or we use two forms and sync state (not "same form"). Spec says "Overlay uses the same form and Alpine state as inline". So one Alpine component, one form. Solution: the capture box div wraps everything. Inside it: (1) Inline area: when `!focusOverlayOpen`, show the form here. (2) Overlay: when `focusOverlayOpen`, show a full-screen layer with the same form. That would require the form to be in two places in the DOM—we can't. So the form must be in one place. If the form is in the inline area, we can use CSS to expand the inline area to full screen when focus mode (e.g. the capture box gets `class="fixed inset-0 z-50 ..."` when focus, and the rest of the page is hidden or covered). So: one wrapper div with captureBox. When focusOverlayOpen, add classes to that wrapper to make it fixed full screen with backdrop behind it (the "backdrop" can be a sibling that's also shown when focus). So structure: `<div x-data="captureBox()" :class="{ 'fixed inset-0 z-50 flex items-center justify-center': focusOverlayOpen }">` and when focus, show a backdrop sibling `<div x-show="focusOverlayOpen" @click="focusOverlayOpen = false" class="absolute inset-0 bg-black/50 -z-10">` and the form is in the same wrapper. So the whole capture box becomes a full-screen centered box when focus. But the capture box is in the flow of the page; when we add `fixed inset-0`, it will cover the page. The form content stays in the center (max-w container). Add a "Close" button and Escape listener. So we don't duplicate the form; we just change the wrapper's class when focus. That works. Implement: capture box div gets `:class="focusOverlayOpen ? 'fixed inset-0 z-50 flex items-center justify-center p-4' : ''"` and when focus, inside it a backdrop div (absolute inset-0 bg-black/50, z-below) that has @click="focusOverlayOpen = false". Then the form container (the current rounded box) stays in the center. Add role="dialog" and aria-modal when focus. So the plan: (1) Add data-drafts-url and keep data-focus-reply. (2) Add draft list above form, x-show="drafts.length > 0 && !isReplyMode", collapsed by default with "Drafts (n)" toggle. (3) Add "Focus" button that sets focusOverlayOpen = true. (4) Make the capture box wrapper conditional class so when focusOverlayOpen it becomes fixed full screen; add backdrop div that closes on click; add Close button; Escape closes. (5) Focus trap: when opening, focus the textarea; when closing, focus the Focus button. This is all in the Blade and Alpine; the Alpine logic is in the next task.

- [ ] **Step 5: Commit**

```bash
git add resources/views/idea/index.blade.php
git commit -m "Add draft list UI, Focus button, and focus overlay structure to capture box"
```

---

### Task 5: Alpine captureBox — drafts fetch, auto-save, load/discard, focus overlay

**Files:**
- Modify: `resources/js/app.js`

- [ ] **Step 1: Extend captureBox state**

Add to `captureBox()` in `resources/js/app.js`:

- `drafts: []` — list of `{ id, content_preview, updated_at, updated_at_human }`.
- `currentDraftId: null` — uuid when form is bound to a draft.
- `draftsExpanded: false` — whether draft list is expanded.
- `draftSaveTimeout: null` — for debounce.
- `focusOverlayOpen: false`.
- `isReplyMode: false` — set in init from `this._rootEl.dataset.focusReply === '1'`.

In `init()`, set `isReplyMode = this._rootEl.dataset.focusReply === '1'`. If `!isReplyMode`, call `fetchDrafts()`.

- [ ] **Step 2: fetchDrafts**

- `async fetchDrafts()`: GET `this._rootEl.dataset.draftsUrl` with Accept: application/json and CSRF if needed. On success, set `this.drafts = response.json()`. On failure, leave drafts as-is. Call from init when !isReplyMode.

- [ ] **Step 3: Debounced auto-save**

- When content or no_chunking changes, clear any existing `draftSaveTimeout`, then set `draftSaveTimeout = setTimeout(() => this.saveDraft(), 1500)` (1.5 s). Use Alpine `$watch` on `content` and the checkbox state, or trigger on input/change. If `isReplyMode`, do not run auto-save. If content is empty (trim), do not POST (do not create draft). If we have `currentDraftId`, PATCH that draft; else POST to create and set `currentDraftId` from response. On success, refresh draft list (fetchDrafts). On failure, optionally set a small "Draft couldn't be saved" message and do not clear form.

- [ ] **Step 4: loadDraft(id)**

- GET `draftsUrl + '/' + id`, then set `content`, `no_chunking` from response, and `currentDraftId = id`. Focus textarea. Optionally collapse draft list.

- [ ] **Step 5: discardDraft(id)**

- DELETE `draftsUrl + '/' + id`. On success, remove from local `drafts`; if `currentDraftId === id`, set `currentDraftId = null` and clear content and no_chunking. Optionally confirm before delete if content length > 0.

- [ ] **Step 6: submitCapture — delete bound draft on success**

In existing `submitCapture()`, after a successful store response (status ok), if `this.currentDraftId` is set, call DELETE `draftsUrl + '/' + this.currentDraftId`, then set `currentDraftId = null`. Then clear content and show success as today. Refresh draft list (fetchDrafts).

- [ ] **Step 7: Focus overlay**

- `toggleFocus()` or open: set `focusOverlayOpen = true`, then `$nextTick(() => this.focusCapture())`. Close: set `focusOverlayOpen = false`, then focus the Focus button (e.g. `this.$refs.focusButton?.focus()`). On Escape when overlay is open, close overlay. Use `@keydown.escape.window` when `focusOverlayOpen` or a keydown handler on the overlay. Backdrop click: already in Blade with `@click="focusOverlayOpen = false"`.

- [ ] **Step 8: Wire checkbox to Alpine**

Ensure the "Don't split" checkbox is bound to Alpine (e.g. `x-model` or `:checked` and `@change`) so auto-save can read it. If it's not already, add `x-model="noChunking"` or similar and include in the form submit and draft payload.

- [ ] **Step 9: Commit**

```bash
git add resources/js/app.js
git commit -m "captureBox: drafts fetch, debounced auto-save, load/discard, focus overlay, delete draft on store"
```

---

### Task 6: Polish — no_chunking in form, error message for draft save failure

**Files:**
- Modify: `resources/views/idea/index.blade.php` (checkbox binding if not already)
- Modify: `resources/js/app.js` (draft save error message)

- [ ] **Step 1: Bind no_chunking**

Ensure the "Don't split into sections" checkbox value is sent with draft create/update and with Store thought. In Blade, add `x-model="noChunking"` to the checkbox and ensure the form includes it (hidden or checkbox name). In captureBox, add `noChunking: false` to state and in saveDraft send `no_chunking: noChunking`. When loading a draft, set `noChunking = draft.no_chunking`. When building FormData for submitCapture, include no_chunking from state.

- [ ] **Step 2: Optional draft save error**

If auto-save PATCH/POST fails, set a short-lived message like "Draft couldn't be saved" (reuse message/messageType or a separate draftMessage) and retry on next debounce. Do not block the user.

- [ ] **Step 3: Run tests and manual check**

```bash
php artisan test tests/Feature/DraftControllerTest.php
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/index.blade.php resources/js/app.js
git commit -m "Bind no_chunking to drafts and store; optional draft save error message"
```

---

## Execution handoff

After implementing all tasks:

- Run full test suite: `php artisan test` (or `./vendor/bin/pest`).
- Manually verify: load idea index, type in form, wait for auto-save; open draft list, Resume, Discard; Store thought and confirm draft is removed; open Focus, type, close with Escape/backdrop/Close; reply mode has no draft list and no auto-save.

**Plan complete and saved to `docs/superpowers/plans/2026-03-17-thought-drafts-and-focus.md`. Ready to execute?**

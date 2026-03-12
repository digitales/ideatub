# Tag-based navigation and Stream page — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Stream page showing all thoughts (with optional tag filter and pagination), make tags on thoughts clickable (linking to Stream by tag), and add a Stream link in the idea nav. Homepage remains “recent + capture” with no logic changes except tag links.

**Architecture:** New `GET /stream` route handled by `IdeaController::stream()`. Stream view reuses idea layout (no capture box); thought list markup is duplicated from index in v1 (partial can be extracted later). Tag query uses `whereJsonContains('metadata->tags', $tag)`. Homepage and Stream thought cards render tags as `<a href="{{ route('idea.stream', ['tag' => $tag]) }}">`.

**Tech Stack:** Laravel, Blade, existing idea layout. No new frontend dependencies.

**Spec:** `docs/superpowers/specs/2026-03-12-tag-and-stream-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `routes/web.php` | Add `GET /stream` → `IdeaController@stream`, name `idea.stream` (inside auth group). |
| `app/Http/Controllers/IdeaController.php` | Add `stream(Request $request)` method: validate optional `tag`, query top-level thoughts for user (with tag filter when present), paginate(20), load comments; return `idea.stream` view with `thoughts`, `tag`, pagination. |
| `resources/views/idea/stream.blade.php` | New view extending `layouts/idea`. Title “Stream” or “All thoughts”; when `$tag` set, show “Tag: {tag}” with link to clear. List thoughts (same card structure as index); tags as links to `route('idea.stream', ['tag' => $tag])`. Empty states: no thoughts at all; no thoughts for tag. Pagination links. |
| `resources/views/idea/index.blade.php` | Change tag `<span>` to `<a href="{{ route('idea.stream', ['tag' => $tag]) }}">` with same pill classes. |
| `resources/views/layouts/idea.blade.php` | Add “Stream” (or “All thoughts”) link in right nav (e.g. between Example Prompts and Help). |
| `tests/Feature/StreamPageTest.php` | New: stream page loads for auth user; redirects guests; shows all user thoughts; tag filter shows only thoughts with that tag; empty states; nav and tag links present. |

---

## Chunk 1: Stream route, controller, and basic view

### Task 1.1: Route and controller method (no tag filter yet)

**Files:** Modify `routes/web.php`, Modify `app/Http/Controllers/IdeaController.php`

- [ ] **Step 1: Write failing test — stream page loads for authenticated user**

Create `tests/Feature/StreamPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Stream', false);
    }

    public function test_stream_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.stream'));

        $response->assertRedirect(route('login'));
    }
}
```

Run: `php artisan test tests/Feature/StreamPageTest.php`  
Expected: FAIL (route or method does not exist).

- [ ] **Step 2: Add route**

In `routes/web.php`, inside the `auth` middleware group (after the existing idea routes), add:

```php
Route::get('/stream', [IdeaController::class, 'stream'])->name('idea.stream');
```

- [ ] **Step 3: Add controller method**

In `app/Http/Controllers/IdeaController.php`, add a `stream` method that:

- Takes `Request $request`.
- Builds query: `Thought::query()->where('user_id', auth()->id())->topLevel()->with(['comments' => fn ($q) => $q->orderBy('created_at')])->orderByDesc('created_at')`.
- Paginates with `->paginate(20)` (use a constant e.g. `STREAM_PAGE_SIZE = 20`).
- Returns `view('idea.stream', ['thoughts' => $thoughts, 'tag' => null])`.

Add `use Illuminate\Http\Request` if not already present.

- [ ] **Step 4: Create minimal stream view**

Create `resources/views/idea/stream.blade.php`:

```blade
@extends('layouts.idea')

@section('title', 'Stream — IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">Stream</h1>

    @if ($thoughts->isEmpty())
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            No thoughts yet. <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline">Capture one from the home page</a>.
        </div>
    @else
        <p class="text-[11px] text-slate-brand/40 mb-2">{{ $thoughts->total() }} thoughts</p>
        @foreach ($thoughts as $thought)
            <div class="rounded-xl border border-memory-violet/10 bg-white/68 backdrop-blur px-4 py-3.5 mb-2">
                <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2">{{ e($thought->content) }}</p>
                <p class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</p>
            </div>
        @endforeach
        @if ($thoughts->hasMorePages())
            <div class="mt-4 text-center">
                {{ $thoughts->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/StreamPageTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php resources/views/idea/stream.blade.php tests/Feature/StreamPageTest.php
git commit -m "feat(stream): add Stream page with paginated thoughts"
```

---

## Chunk 2: Tag filter and empty states on Stream

### Task 2.1: Tag query parameter and filter

**Files:** Modify `app/Http/Controllers/IdeaController.php`, Modify `resources/views/idea/stream.blade.php`, Modify `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Write failing test — tag filter**

In `StreamPageTest.php`, add:

```php
public function test_stream_tag_filter_shows_only_matching_thoughts(): void
{
    $user = User::factory()->create();
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Work thought',
        'metadata' => ['tags' => ['work']],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Personal thought',
        'metadata' => ['tags' => ['personal']],
    ]);

    $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

    $response->assertStatus(200);
    $response->assertSee('Work thought');
    $response->assertDontSee('Personal thought');
}
```

Add `use App\Models\Thought;` at top of test file.

Run: `php artisan test tests/Feature/StreamPageTest.php --filter=test_stream_tag_filter`  
Expected: FAIL (Personal thought may still appear until we add filter).

- [ ] **Step 2: Validate and apply tag in controller**

In `IdeaController::stream()`:

- Validate: `$request->validate(['tag' => 'nullable|string|max:100']);`
- `$tag = $request->input('tag');` then if `$tag !== null && $tag !== ''` (after trim), apply `$query->whereJsonContains('metadata->tags', $tag)` before `orderByDesc` and paginate.
- Pass `tag` to view: `['thoughts' => $thoughts, 'tag' => $tag ? trim($tag) : null]`. If tag is empty string after trim, treat as null.

- [ ] **Step 3: Run test**

Run: `php artisan test tests/Feature/StreamPageTest.php`  
Expected: PASS (tag filter test passes).

- [ ] **Step 4: Stream view — tag header and empty state for tag**

In `stream.blade.php`:

- If `$tag` is set: show a line like “Tag: {{ e($tag) }}” with a link “All thoughts” pointing to `route('idea.stream')`. Place above the list.
- In the `@else` branch (when there are thoughts), if `$tag` is set and `$thoughts->isEmpty()`, show: “No thoughts with tag ‘{{ e($tag) }}’.” and link “All thoughts” to `route('idea.stream')`. But with the current structure, when there are no thoughts we already show “No thoughts yet…”. So: when `$tag` is set and `$thoughts->isEmpty()`, show “No thoughts with tag ‘{{ e($tag) }}’.” and link to Stream. Adjust the single empty block: if `$tag`, show tag empty state; else show “No thoughts yet…”.

- [ ] **Step 5: Add test for empty tag result**

In `StreamPageTest.php`:

```php
public function test_stream_tag_filter_empty_shows_message(): void
{
    $user = User::factory()->create();
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Only work',
        'metadata' => ['tags' => ['work']],
    ]);

    $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'nonexistent']));

    $response->assertStatus(200);
    $response->assertSee('No thoughts with tag');
    $response->assertSee('All thoughts', false);
}
```

Run tests; fix view if needed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/stream.blade.php tests/Feature/StreamPageTest.php
git commit -m "feat(stream): add tag filter and empty states"
```

---

## Chunk 3: Full thought cards on Stream and tag links

### Task 3.1: Stream view — full thought cards with tags and comments

**Files:** Modify `resources/views/idea/stream.blade.php`

- [ ] **Step 1: Reuse thought card structure from index**

In `stream.blade.php`, replace the minimal card with the same structure as `idea/index.blade.php`: include metadata (source), tags (as pills — for now plain pills; next task will make them links), and nested comments. Use the same `$tagColors`, `$tagMap` and loop over `$thought->metadata['tags'] ?? []`. Do not include Reply link (Stream is read-only browse) or the j/k selection attributes. Omit `data-index` / `data-reply-href` and the ring selection class. Keep: content, timestamp, source, tags, nested comments list.

- [ ] **Step 2: Make tags links on Stream**

In the tag loop in `stream.blade.php`, render each tag as:

```blade
<a href="{{ route('idea.stream', ['tag' => $tag]) }}" class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }} hover:opacity-90">
    #{{ $tag }}
</a>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/idea/stream.blade.php
git commit -m "feat(stream): full thought cards with tag links"
```

### Task 3.2: Homepage — make tags clickable

**Files:** Modify `resources/views/idea/index.blade.php`

- [ ] **Step 1: Replace tag span with link**

In `resources/views/idea/index.blade.php`, find the tag loop (around lines 157–161). Change:

```blade
@foreach ($tags as $i => $tag)
    <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }}">
        #{{ $tag }}
    </span>
@endforeach
```

to:

```blade
@foreach ($tags as $i => $tag)
    <a href="{{ route('idea.stream', ['tag' => $tag]) }}" class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }} hover:opacity-90">
        #{{ $tag }}
    </a>
@endforeach
```

- [ ] **Step 2: Optional — test tag link on homepage**

In `StreamPageTest.php` or `IdeaPageTest.php`, add a test that creates a thought with tags and asserts the response contains `route('idea.stream', ['tag' => 'work'])` (e.g. `assertSee(route('idea.stream', ['tag' => 'work']), false)`).

- [ ] **Step 3: Commit**

```bash
git add resources/views/idea/index.blade.php tests/Feature/StreamPageTest.php
git commit -m "feat(idea): make tags on homepage link to Stream by tag"
```

### Task 3.3: Nav link to Stream

**Files:** Modify `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Add Stream link**

In the right nav (where “Example Prompts” and “Help” are), add a link before or after “Example Prompts”:

```blade
<a href="{{ route('idea.stream') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
    Stream
</a>
```

- [ ] **Step 2: Test**

In `StreamPageTest.php`, add:

```php
public function test_stream_link_in_nav(): void
{
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('idea.index'));
    $response->assertStatus(200);
    $response->assertSee('Stream', false);
    $response->assertSee(route('idea.stream'), false);
}
```

Run: `php artisan test tests/Feature/StreamPageTest.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/idea.blade.php tests/Feature/StreamPageTest.php
git commit -m "feat(nav): add Stream link to idea layout"
```

---

## Chunk 4: Polish and verification

### Task 4.1: Stream “Tag: x” chip and clear link

**Files:** Modify `resources/views/idea/stream.blade.php`

- [ ] **Step 1: When tag is set, show chip with clear**

Ensure when `$tag` is present we show a clear “Tag: {tag}” and “All thoughts” link near the top (already added in Chunk 2). If not already styled as a chip, use a small pill/card style consistent with the app.

- [ ] **Step 2: Page title when filtered**

In `stream.blade.php`, change `@section('title')` to: when `$tag` is set, “Tag: {{ e($tag) }} — IdeaTub”, else “Stream — IdeaTub”.

- [ ] **Step 3: Run full test suite**

Run: `php artisan test`  
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/stream.blade.php
git commit -m "chore(stream): title and tag chip polish"
```

---

## Verification checklist

- [ ] Authenticated user can open `/stream` and see paginated thoughts.
- [ ] `/stream?tag=work` shows only thoughts with tag `work`; empty state when none.
- [ ] Homepage thought tags are links to `/stream?tag=...`; Stream thought tags are links too.
- [ ] Nav shows “Stream” linking to `/stream`.
- [ ] Guests hitting `/stream` are redirected to login.
- [ ] `php artisan test` passes.

# Thought Detail Pages Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated detail page for thoughts so all cards are clickable, with richer email-specific rendering and reply support.

**Architecture:** Reuse the existing `IdeaController` and `Thought`/`ImportedEmail` models to add one authenticated show route and one shared detail page shell. Keep the list pages summary-first by linking current card templates to the new route, and enrich only `source = email` thoughts with imported-email sidebar data.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, existing `IdeaController` routes/views, Eloquent relationships, PHPUnit-style Laravel feature tests run with the repo's existing test harness.

**Spec:** `docs/superpowers/specs/2026-03-20-thought-detail-pages-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/IdeaController.php` | Add the thought detail show action, owner-only access, imported-email enrichment, and reply-page data loading. |
| `app/Models/Thought.php` | Add a helper relationship or query helper for the linked imported email so controller/view code stays simple. |
| `routes/web.php` | Register the authenticated thought detail route. |
| `resources/views/idea/show.blade.php` | Render the shared thought detail page shell for all sources. |
| `resources/views/idea/partials/thought_detail_header.blade.php` | Render the shared top section with content/source/timestamps. |
| `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` | Render the email metadata sidebar for `source = email`. |
| `resources/views/idea/partials/thought_detail_replies.blade.php` | Render replies/comments and the reply form on the detail page. |
| `resources/views/idea/index_thought_cards.blade.php` | Make index cards clickable without breaking card actions and reply affordances. |
| `resources/views/idea/stream_thoughts.blade.php` | Make stream cards clickable without breaking card actions. |
| `tests/Feature/ThoughtShowPageTest.php` | Cover thought detail access, generic rendering, email rendering, and reply flow. |
| `tests/Feature/IdeaPageTest.php` | Assert index cards link to the detail page. |
| `tests/Feature/StreamPageTest.php` | Assert stream cards link to the detail page. |

---

## Scope notes

- This plan covers the shared card surfaces already used by the authenticated home/index and stream pages: `resources/views/idea/index_thought_cards.blade.php` and `resources/views/idea/stream_thoughts.blade.php`.
- If implementation reveals other authenticated thought-card surfaces reusing materially different markup, either apply the same link pattern in the same slice or document them explicitly as follow-up work before closing the task.

---

## Chunk 1: Route and generic thought detail page

### Task 1.1: Add the failing detail-page feature tests

**Files:**
- Create: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Write the failing auth/ownership/detail tests**

Create `tests/Feature/ThoughtShowPageTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtShowPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_owner_can_view_thought_detail_page(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Full detail thought body',
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Full detail thought body');
    }

    public function test_other_user_cannot_view_thought_detail_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);

        $response = $this->actingAs($other)->get(route('thoughts.show', $thought));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_thought_detail_page(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'embedding' => null,
        ]);

        $response = $this->get(route('thoughts.show', $thought));

        $response->assertRedirect(route('login'));
    }

    public function test_missing_thought_returns_not_found(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get('/thoughts/00000000-0000-0000-0000-000000000001');

        $response->assertNotFound();
    }

    public function test_thought_detail_page_shows_existing_replies(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'content' => 'Parent thought',
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $thought->id,
            'content' => 'Existing reply content',
            'embedding' => null,
        ]);

        $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertSee('Existing reply content');
        $response->assertSee('Reply');
    }
}
```

- [ ] **Step 2: Run the new test file**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: FAIL because `thoughts.show` does not exist yet.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ThoughtShowPageTest.php
git commit -m "test: add thought detail page feature tests"
```

---

### Task 1.2: Add the show route and minimal show action

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Add the authenticated show route**

In `routes/web.php`, inside the authenticated group, add:

```php
Route::get('/thoughts/{thought}', [IdeaController::class, 'show'])->name('thoughts.show');
```

- [ ] **Step 2: Run the test file again**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: FAIL because `IdeaController::show()` does not exist yet.

- [ ] **Step 3: Add the minimal show action**

In `app/Http/Controllers/IdeaController.php`, add a `show()` action that:

- type-hints `Thought $thought`
- aborts with `403` when `auth()->id() !== $thought->user_id`
- loads ordered comments via `$thought->load(['comments' => fn ($q) => $q->orderBy('created_at')])`
- returns `view('idea.show', ['thought' => $thought, 'importedEmail' => null])`

- [ ] **Step 4: Create the minimal view**

Create `resources/views/idea/show.blade.php`:

```blade
@extends('layouts.idea')

@section('title', 'Thought — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50 mb-2">Thought detail</p>
        <p class="text-[15px] text-deep-indigo leading-relaxed whitespace-pre-line">{{ $thought->content }}</p>
    </div>

    @if ($thought->comments->isNotEmpty())
        <div class="mt-6 space-y-3">
            @foreach ($thought->comments as $comment)
                <div class="rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3">
                    <p class="text-[13px] text-slate-brand whitespace-pre-line">{{ $comment->content }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}" class="text-sm text-memory-violet hover:underline">Reply</a>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Re-run the feature tests**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php resources/views/idea/show.blade.php
git commit -m "feat: add thought detail page route"
```

---

## Chunk 2: Email enrichment and shared page partials

### Task 2.1: Load imported-email data for email thoughts

**Files:**
- Modify: `app/Models/Thought.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Extend the test file for email detail rendering**

Append to `tests/Feature/ThoughtShowPageTest.php`:

```php
use App\Models\ImportedEmail;
use App\Models\MailAccount;

public function test_email_thought_detail_page_shows_body_and_email_metadata(): void
{
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'content' => 'Fallback thought body',
        'source' => 'email',
        'source_metadata' => [
            'imported_email_id' => 1,
            'subject' => 'Fallback subject',
        ],
        'embedding' => null,
    ]);

    $account = MailAccount::factory()->create(['user_id' => $owner->id]);
    $imported = ImportedEmail::create([
        'user_id' => $owner->id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'msg-1',
        'provider_thread_id' => 'thread-1',
        'direction' => 'received',
        'subject' => 'Imported subject',
        'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        'to_json' => [['email' => 'owner@example.com', 'name' => 'Owner']],
        'participants_json' => [['role' => 'from', 'email' => 'sender@example.com', 'name' => 'Sender']],
        'sent_at' => now()->subMinute(),
        'received_at' => now(),
        'body_text' => 'Imported email body text',
        'processing_status' => 'imported',
        'thought_id' => $thought->id,
    ]);

    $thought->update([
        'source_metadata' => array_merge($thought->source_metadata ?? [], ['imported_email_id' => $imported->id]),
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('Imported email body text');
    $response->assertSee('Imported subject');
    $response->assertSee('sender@example.com');
    $response->assertSee('received');
}

public function test_email_thought_detail_page_falls_back_when_imported_email_is_missing(): void
{
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'content' => 'Fallback email body from thought',
        'source' => 'email',
        'source_metadata' => [
            'imported_email_id' => 999999,
            'subject' => 'Fallback metadata subject',
            'from' => [
                ['email' => 'fallback@example.com', 'name' => 'Fallback Sender'],
            ],
        ],
        'embedding' => null,
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('Fallback email body from thought');
    $response->assertSee('Fallback metadata subject');
    $response->assertSee('fallback@example.com');
}
```

- [ ] **Step 2: Run the test file**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: FAIL because the controller/view do not load or render imported email data yet.

- [ ] **Step 3: Add a helper on `Thought`**

In `app/Models/Thought.php`, add a helper like:

```php
public function importedEmail(): ?ImportedEmail
{
    $importedEmailId = data_get($this->source_metadata, 'imported_email_id');
    if ($importedEmailId) {
        return ImportedEmail::where('user_id', $this->user_id)->find($importedEmailId);
    }

    return ImportedEmail::query()
        ->where('user_id', $this->user_id)
        ->where('thought_id', $this->id)
        ->first();
}
```

- [ ] **Step 4: Update the show action**

In `IdeaController::show()`:

- resolve `$importedEmail = $thought->source === 'email' ? $thought->importedEmail() : null`
- keep the lookup order aligned with the spec: `source_metadata.imported_email_id` first, then fallback by `thought_id`
- pass it into the view

- [ ] **Step 5: Re-run the test**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: still FAIL, but now because the email-specific content is not rendered yet.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Thought.php app/Http/Controllers/IdeaController.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: load imported email data for thought detail pages"
```

---

### Task 2.2: Build the shared detail page shell and email sidebar

**Files:**
- Create: `resources/views/idea/partials/thought_detail_header.blade.php`
- Create: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Create: `resources/views/idea/partials/thought_detail_replies.blade.php`
- Modify: `resources/views/idea/show.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Split the show page into partials**

Create `resources/views/idea/partials/thought_detail_header.blade.php` for the shared top section:

- thought source
- created timestamp
- top-level content for non-email thoughts

Create `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` for:

- subject
- direction
- from/to/cc
- sent/received timestamps
- provider/provider message/thread/mailbox context when present
- fallback values from `source_metadata` when the imported row is missing
- a layout that stacks below the main content on smaller screens

Create `resources/views/idea/partials/thought_detail_replies.blade.php` for:

- existing replies/comments
- a shared replies/comments section that the next chunk can extend with the inline reply form

- [ ] **Step 2: Update the main show template**

In `resources/views/idea/show.blade.php`:

- keep one shared shell
- render the main body area from `$importedEmail->body_text` when `$thought->source === 'email' && $importedEmail`
- otherwise render `$thought->content`
- render non-email metadata/tags when present so generic thought detail pages are richer than cards
- include the email sidebar partial only for email thoughts
- include the replies partial for all thoughts

- [ ] **Step 3: Run the feature test file**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_header.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php resources/views/idea/partials/thought_detail_replies.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: render source-aware thought detail pages"
```

---

## Chunk 3: Make cards clickable

### Task 3.1: Link index thought cards to the detail page

**Files:**
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `tests/Feature/IdeaPageTest.php`

- [ ] **Step 1: Add the failing index-page link assertion**

Append to `tests/Feature/IdeaPageTest.php`:

```php
public function test_idea_page_thought_cards_link_to_detail_page(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Linked thought',
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertOk();
    $response->assertSee(route('thoughts.show', $thought), false);
}
```

- [ ] **Step 2: Run the targeted test**

Run: `vendor/bin/phpunit tests/Feature/IdeaPageTest.php --filter thought_cards_link_to_detail_page`

Expected: FAIL because index cards do not link to the new route yet.

- [ ] **Step 3: Update the card markup**

In `resources/views/idea/index_thought_cards.blade.php`:

- wrap the non-action body in an `<a href="{{ route('thoughts.show', $thought) }}">`
- keep the actions menu outside the clickable anchor
- keep the existing reply link working
- preserve the keyboard-navigation data attributes already used by the page
- ensure the final markup does not nest anchors or buttons inside the clickable link

- [ ] **Step 4: Re-run the targeted test**

Run: `vendor/bin/phpunit tests/Feature/IdeaPageTest.php --filter thought_cards_link_to_detail_page`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/idea/index_thought_cards.blade.php tests/Feature/IdeaPageTest.php
git commit -m "feat: link index thought cards to detail pages"
```

---

### Task 3.2: Link stream thought cards to the detail page

**Files:**
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add the failing stream-page link assertion**

Append to `tests/Feature/StreamPageTest.php`:

```php
public function test_stream_thought_cards_link_to_detail_page(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Stream linked thought',
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->withoutVite()->get(route('idea.stream'));

    $response->assertOk();
    $response->assertSee(route('thoughts.show', $thought), false);
}
```

- [ ] **Step 2: Run the targeted test**

Run: `vendor/bin/phpunit tests/Feature/StreamPageTest.php --filter stream_thought_cards_link_to_detail_page`

Expected: FAIL because stream cards do not link to the detail page yet.

- [ ] **Step 3: Update the stream card template**

In `resources/views/idea/stream_thoughts.blade.php`:

- make the card body clickable to the show route
- keep action controls and any existing formatted-research link valid
- avoid invalid nested anchors by restructuring the card if necessary

- [ ] **Step 4: Sanity-check the rendered HTML structure**

Confirm both updated card templates keep interactive controls outside the main `<a>` so the page does not produce nested anchors.

- [ ] **Step 5: Re-run the targeted test**

Run: `vendor/bin/phpunit tests/Feature/StreamPageTest.php --filter stream_thought_cards_link_to_detail_page`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/idea/stream_thoughts.blade.php tests/Feature/StreamPageTest.php
git commit -m "feat: link stream thought cards to detail pages"
```

---

## Chunk 4: Reply flow and final verification

### Task 4.1: Verify reply flow from the detail page

**Files:**
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Modify: `resources/views/idea/partials/thought_detail_replies.blade.php`

- [ ] **Step 1: Add the failing reply-flow test**

Append to `tests/Feature/ThoughtShowPageTest.php`:

```php
public function test_thought_detail_page_renders_an_inline_reply_form(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Parent detail thought',
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('name="parent_id"', false);
    $response->assertSee('value="'.$thought->id.'"', false);
    $response->assertSee(route('thoughts.store'), false);
}

public function test_user_can_post_a_reply_from_the_detail_page(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Parent detail thought',
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->from(route('thoughts.show', $thought))->post(route('thoughts.store'), [
        'content' => 'Reply from detail page',
        'parent_id' => $thought->id,
    ]);

    $response->assertRedirect(route('idea.index'));
    $this->assertDatabaseHas('thoughts', [
        'user_id' => $user->id,
        'parent_id' => $thought->id,
    ]);
}
```

- [ ] **Step 2: Run the test file**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: FAIL because the detail page does not yet render a proven inline reply form.

- [ ] **Step 3: If needed, add the reply affordance**

Update `thought_detail_replies.blade.php` so the page contains:

- a `POST` form to `route('thoughts.store')`
- a hidden `parent_id`
- a textarea for reply content
- a submit button

Keep redirect behavior aligned with current `IdeaController::store()` behavior unless product requirements explicitly change it.

- [ ] **Step 4: Re-run the test file**

Run: `vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ThoughtShowPageTest.php resources/views/idea/partials/thought_detail_replies.blade.php
git commit -m "feat: preserve reply flow on thought detail pages"
```

---

### Task 4.2: Final verification

**Files:**
- Verify only

- [ ] **Step 1: Run the targeted detail-page tests**

Run:

```bash
vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php
vendor/bin/phpunit tests/Feature/IdeaPageTest.php --filter thought_cards_link_to_detail_page
vendor/bin/phpunit tests/Feature/StreamPageTest.php --filter stream_thought_cards_link_to_detail_page
```

Expected: PASS.

- [ ] **Step 2: Run the broader surrounding feature tests**

Run:

```bash
vendor/bin/phpunit tests/Feature/IdeaPageTest.php
vendor/bin/phpunit tests/Feature/StreamPageTest.php
vendor/bin/phpunit tests/Feature/ThoughtShowPageTest.php
```

Expected: PASS.

- [ ] **Step 3: Check lints for touched files**

Run `ReadLints` on:

- `app/Http/Controllers/IdeaController.php`
- `app/Models/Thought.php`
- `resources/views/idea/show.blade.php`
- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- `tests/Feature/ThoughtShowPageTest.php`

Expected: no new lint errors.

- [ ] **Step 4: Final commit**

```bash
git status
git add app resources routes tests
git commit -m "feat: add thought detail pages"
```

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-03-20-thought-detail-pages.md`. Ready to execute?

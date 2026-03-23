# Email Research Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add "Run idea research" and "Run newsletter research" menu items to the email thought actions menu, backed by a new `EmailResearchController`.

**Architecture:** A new `EmailResearchController` handles two POST routes under the existing `auth` middleware group. The `ideaResearch` method dispatches the existing `IdeaResearchRequested` event; `newsletterResearch` resets email processing state and re-dispatches `ProcessExtraEmailResearch`. The Blade actions menu partial is extended with two form-submit buttons, conditionally shown for email thoughts.

**Tech Stack:** Laravel 11, Blade, PHPUnit feature tests with `RefreshDatabase`.

---

## File Map

| Action | Path |
|--------|------|
| Create | `app/Http/Controllers/EmailResearchController.php` |
| Modify | `routes/web.php` — add 2 routes + `use` import |
| Modify | `resources/views/idea/partials/thought_card_actions.blade.php` — add 2 menu items |
| Create | `tests/Feature/EmailResearchControllerTest.php` |

---

## Task 1: Write failing tests for `ideaResearch`

**Files:**
- Create: `tests/Feature/EmailResearchControllerTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

namespace Tests\Feature;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailResearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeEmailThought(User $user, array $overrides = []): Thought
    {
        return Thought::factory()->create(array_merge([
            'user_id' => $user->id,
            'source'  => 'email',
        ], $overrides));
    }

    private function attachImportedEmail(User $user, Thought $thought): ImportedEmail
    {
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        // Use a real Thought ID to satisfy any FK constraint on research_thought_id.
        $priorResearchThought = Thought::factory()->create(['user_id' => $user->id]);

        $email = ImportedEmail::query()->create([
            'user_id'            => $user->id,
            'mail_account_id'    => $account->id,
            'provider'           => 'fastmail',
            'provider_message_id'=> 'test-msg-'.uniqid(),
            'direction'          => 'received',
            'subject'            => 'Test newsletter',
            'body_text'          => 'Test body.',
            'from_json'          => [['email' => 'news@example.com', 'name' => 'News']],
            'processing_status'  => 'research_completed',
            'thought_id'         => $thought->id,
            'research_thought_id'=> $priorResearchThought->id,
            'failure_count'      => 1,
            'failure_reason'     => 'prior failure',
        ]);

        return $email;
    }

    // -----------------------------------------------------------------------
    // ideaResearch
    // -----------------------------------------------------------------------

    public function test_idea_research_dispatches_event_for_email_thought(): void
    {
        Event::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        $response = $this->actingAs($user)
            ->post(route('emails.idea-research', $thought));

        $response->assertRedirect();
        Event::assertDispatched(IdeaResearchRequested::class, function ($event) use ($thought) {
            return $event->idea->id === $thought->id && $event->source === 'email';
        });
        $thought->refresh();
        $this->assertTrue((bool) ($thought->metadata['research_pending'] ?? false));
    }

    public function test_idea_research_requires_authentication(): void
    {
        $thought = Thought::factory()->create(['source' => 'email']);

        $this->post(route('emails.idea-research', $thought))
            ->assertRedirect(route('login'));
    }

    public function test_idea_research_rejects_non_owner(): void
    {
        Event::fake();

        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.idea-research', $thought))
            ->assertForbidden();
    }

    public function test_idea_research_rejects_non_email_thought(): void
    {
        Event::fake();

        $user    = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.idea-research', $thought))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // newsletterResearch
    // -----------------------------------------------------------------------

    public function test_newsletter_research_requeues_for_imported_email(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user, [
            'source_metadata' => ['newsletter_research' => ['status' => 'research_completed']],
        ]);
        $email = $this->attachImportedEmail($user, $thought);

        $response = $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought));

        $response->assertRedirect();
        Queue::assertPushed(ProcessExtraEmailResearch::class, function ($job) use ($email) {
            return $job->importedEmailId === $email->id && $job->capturedInboundEmailId === null;
        });
        $email->refresh();
        $this->assertSame('research_queued', $email->processing_status);
        $this->assertNull($email->research_thought_id);
        // Spec: failure_count and failure_reason are intentionally not cleared on re-trigger.
        $this->assertSame(1, $email->failure_count);
        $this->assertSame('prior failure', $email->failure_reason);
        $thought->refresh();
        $this->assertNull(data_get($thought->source_metadata, 'newsletter_research'));
    }

    public function test_newsletter_research_requeues_for_captured_inbound_email(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);
        $priorResearch = Thought::factory()->create(['user_id' => $user->id]);
        $captured = CapturedInboundEmail::query()->create([
            'user_id'            => $user->id,
            'message_id'         => 'cap-msg-'.uniqid(),
            'sender_email'       => 'news@example.com',
            'subject'            => 'Postmark newsletter',
            'body_text'          => 'Body text.',
            'processing_status'  => 'research_failed',
            'thought_id'         => $thought->id,
            'research_thought_id'=> $priorResearch->id,
        ]);

        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertRedirect();

        Queue::assertPushed(ProcessExtraEmailResearch::class, function ($job) use ($captured) {
            return $job->capturedInboundEmailId === $captured->id && $job->importedEmailId === null;
        });
        $captured->refresh();
        $this->assertSame('research_queued', $captured->processing_status);
        $this->assertNull($captured->research_thought_id);
    }

    public function test_newsletter_research_returns_404_when_no_email_row(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = $this->makeEmailThought($user);

        // No ImportedEmail or CapturedInboundEmail linked.
        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertNotFound();
    }

    public function test_newsletter_research_requires_authentication(): void
    {
        $thought = Thought::factory()->create(['source' => 'email']);

        $this->post(route('emails.newsletter-research', $thought))
            ->assertRedirect(route('login'));
    }

    public function test_newsletter_research_rejects_non_owner(): void
    {
        Queue::fake();

        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $thought = $this->makeEmailThought($owner);

        $this->actingAs($other)
            ->post(route('emails.newsletter-research', $thought))
            ->assertForbidden();
    }

    public function test_newsletter_research_rejects_non_email_thought(): void
    {
        Queue::fake();

        $user    = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'web']);

        $this->actingAs($user)
            ->post(route('emails.newsletter-research', $thought))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/EmailResearchControllerTest.php
```

Expected: All tests fail — routes not found / controller not found.

---

## Task 2: Create `EmailResearchController`

**Files:**
- Create: `app/Http/Controllers/EmailResearchController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use Illuminate\Http\RedirectResponse;

class EmailResearchController extends Controller
{
    /**
     * Trigger general idea research on an email thought.
     */
    public function ideaResearch(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(403);
        }

        $thought->update([
            'metadata' => array_merge($thought->metadata ?? [], ['research_pending' => true]),
        ]);

        IdeaResearchRequested::dispatch($thought, 'email');

        return redirect()->back()->with('success', 'Idea research started. Refresh in a moment to see results.');
    }

    /**
     * Re-trigger newsletter research on an email thought.
     * Resets processing state so ProcessExtraEmailResearch can run cleanly.
     */
    public function newsletterResearch(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(403);
        }

        $stored = ImportedEmail::where('thought_id', $thought->id)->first()
            ?? CapturedInboundEmail::where('thought_id', $thought->id)->first();

        if ($stored === null) {
            abort(404);
        }

        // Reset so the job's research_thought_id guard does not bail early.
        $stored->processing_status = 'research_queued';
        $stored->research_thought_id = null;
        $stored->save();

        // Clear stale status from the thought so the badge resets.
        $meta = $thought->source_metadata ?? [];
        unset($meta['newsletter_research']);
        $thought->source_metadata = $meta;
        $thought->save();

        if ($stored instanceof ImportedEmail) {
            ProcessExtraEmailResearch::dispatch(importedEmailId: $stored->id);
        } else {
            ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $stored->id);
        }

        return redirect()->back()->with('success', 'Newsletter research queued. Refresh in a moment to see results.');
    }
}
```

---

## Task 3: Add routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Add the `use` import** at the top of `routes/web.php`, alongside the other controller imports:

```php
use App\Http\Controllers\EmailResearchController;
```

- [ ] **Step 2: Add the two routes** inside the `auth` middleware group, after the existing research routes (around line 125):

```php
Route::post('/emails/{thought}/idea-research', [EmailResearchController::class, 'ideaResearch'])->name('emails.idea-research');
Route::post('/emails/{thought}/newsletter-research', [EmailResearchController::class, 'newsletterResearch'])->name('emails.newsletter-research');
```

---

## Task 4: Run tests and verify they pass

- [ ] **Step 1: Run the full test class**

```bash
php artisan test tests/Feature/EmailResearchControllerTest.php
```

Expected: All tests pass.

- [ ] **Step 2: Run the full test suite to catch regressions**

```bash
php artisan test
```

Expected: All tests pass (no regressions).

- [ ] **Step 3: Commit controller, routes, and tests**

```bash
git add app/Http/Controllers/EmailResearchController.php routes/web.php tests/Feature/EmailResearchControllerTest.php
git commit -m "feat: add EmailResearchController with idea and newsletter research endpoints"
```

---

## Task 5: Add UI menu items

**Files:**
- Modify: `resources/views/idea/partials/thought_card_actions.blade.php`

- [ ] **Step 1: Add the two form buttons** inside the `x-show="menuOpen"` dropdown div, above the existing Edit button (before line 53):

```blade
@if (($thought->source ?? null) === 'email')
    <form method="POST" action="{{ route('emails.idea-research', $thought) }}">
        @csrf
        <button type="submit" class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded">
            Run idea research
        </button>
    </form>
    <form method="POST" action="{{ route('emails.newsletter-research', $thought) }}">
        @csrf
        <button type="submit" class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded">
            Run newsletter research
        </button>
    </form>
@endif
```

The full dropdown contents after the edit should read (top-to-bottom): share/manage items (if root thought) → Run idea research (if email) → Run newsletter research (if email) → Edit → Delete.

- [ ] **Step 2: Verify UI renders correctly — navigate to any email thought in a browser and open the `...` menu**

Expect to see "Run idea research" and "Run newsletter research" above "Edit". For non-email thoughts, these items should be absent.

- [ ] **Step 3: Commit UI change**

```bash
git add resources/views/idea/partials/thought_card_actions.blade.php
git commit -m "feat: add run research menu items to email thought actions"
```

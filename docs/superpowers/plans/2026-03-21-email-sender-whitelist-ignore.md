# Email Sender Whitelist & Ignore Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the "Allow" action on email review inbox items both save the sender rule and immediately create a thought; add a "Manage sender rules" navigation link in the inbox header.

**Architecture:** Two isolated changes: (1) `InboxController@applyEmailReviewAction` gains a combined allow path that calls `applySenderClassification` then `saveReviewedEmailAsThought` sequentially; (2) `inbox/index.blade.php` gains a conditional nav link to the existing settings page.

**Tech Stack:** Laravel 11, PHP, Blade templates, PHPUnit/Pest feature tests, SQLite in-memory (RefreshDatabase)

**Spec:** `docs/superpowers/specs/2026-03-21-email-sender-whitelist-ignore-design.md`

---

## Chunk 1: Allow action creates sender rule + thought

**Files:**
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `tests/Feature/EmailReviewInboxTest.php`

### Background

`InboxController@applyEmailReviewAction` currently handles four actions: `allow`, `ignore`, `extra_process`, `save_thought`. The `allow` path only calls `applySenderClassification` (saves rule, marks item done — no thought). We need it to also call `saveReviewedEmailAsThought` after.

The existing test suite in `tests/Feature/EmailReviewInboxTest.php` currently asserts that `allow` does NOT create a thought. These tests will fail with the new behaviour and must be updated before the controller change so TDD is maintained.

---

- [ ] **Step 1: Update `test_user_can_mark_review_sender_as_allow_creates_sender_rule_and_completes_inbox` to expect a thought**

In `tests/Feature/EmailReviewInboxTest.php`, update this test:

```php
public function test_user_can_mark_review_sender_as_allow_creates_sender_rule_and_completes_inbox(): void
{
    $this->fakeOpenRouterForThoughtCapture();

    $user = User::factory()->create();
    ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertRedirect(route('inbox.index'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('email_sender_rules', [
        'user_id' => $user->id,
        'sender_email' => 'newsletter@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);

    $inbox->refresh();
    $imported->refresh(); // must refresh before asserting on thought_id
    $this->assertSame('done', $inbox->status);
    $this->assertNotNull($inbox->actioned_at);
    $this->assertSame(1, Thought::query()->where('source', 'email')->count());
    $this->assertNotNull($imported->thought_id);
    $this->assertSame('imported', $imported->processing_status);
}
```

Remove the `Bus::fake()` call and the `Bus::assertNotDispatched` assertion. Remove the `assertNull($imported->thought_id)` and `assertSame(0, Thought::query()->count())` assertions. Add `fakeOpenRouterForThoughtCapture()` call.

- [ ] **Step 2: Update `test_email_review_action_works_for_captured_inbound_email_source` to expect a thought**

In `tests/Feature/EmailReviewInboxTest.php`, find the exact two-line tail of this test:

```php
        $captured->refresh();
        $this->assertNull($captured->thought_id);
    }
```

Replace it with:

```php
        $captured->refresh();
        $this->assertNotNull($captured->thought_id);
        $this->assertSame('imported', $captured->processing_status);
        $this->assertSame(1, Thought::query()->where('source', 'email')->count());
    }
```

Also add `$this->fakeOpenRouterForThoughtCapture();` at the top of the test (before `Bus::fake()`). Leave `Bus::fake()` in place — it is harmless since `saveReviewedEmailAsThought` does not dispatch jobs.

- [ ] **Step 3: Add `fakeOpenRouterForThoughtCapture()` to tests that post `action=allow`**

The following three tests post `action=allow` as their first request. After the controller change, that request will call `saveReviewedEmailAsThought` which makes HTTP calls to OpenRouter. Add `$this->fakeOpenRouterForThoughtCapture();` at the top of each:

- `test_repeat_classification_post_does_not_mutate_completed_review_item_again`
- `test_repeat_classification_post_returns_already_handled_flash_message`
- `test_sender_classification_updates_existing_rule_row`

- [ ] **Step 4: Update `test_classified_at_uses_same_utc_timestamp_as_inbox_action_flow`**

After the change, `actioned_at` on the `InboxItem` will be the thought-creation timestamp (set by `saveReviewedEmailAsThought`), not the classify timestamp. The classify timestamp is preserved in `processing_metadata_json['email_review_triage']['classified_at']` and in the `InboxItemAction` row.

Update the test to add `fakeOpenRouterForThoughtCapture()` and adjust the `actioned_at` assertion to only verify the classify action row timestamp matches the metadata (not the inbox `actioned_at`):

```php
public function test_classified_at_uses_same_utc_timestamp_as_inbox_action_flow(): void
{
    $this->fakeOpenRouterForThoughtCapture();
    $originalTimezone = config('app.timezone');
    config(['app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');
    Carbon::setTestNow(Carbon::parse('2026-03-21 18:45:12', 'America/New_York'));

    try {
        $user = User::factory()->create();
        ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

        $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
            'action' => 'allow',
        ]);

        $response->assertRedirect(route('inbox.index'));

        $imported->refresh();
        $action = $inbox->actions()->where('action_type', 'email_sender_classify')->sole();
        $rawActionCreatedAt = DB::table('inbox_item_actions')->where('id', $action->id)->value('created_at');

        // The classify timestamp in metadata matches the InboxItemAction row's created_at.
        // Note: inbox_items.actioned_at is now the thought-creation timestamp (from saveReviewedEmailAsThought).
        $this->assertSame(
            Carbon::createFromFormat('Y-m-d H:i:s', $rawActionCreatedAt, 'UTC')->toIso8601String(),
            $imported->processing_metadata_json['email_review_triage']['classified_at'] ?? null
        );
    } finally {
        Carbon::setTestNow();
        config(['app.timezone' => $originalTimezone]);
        date_default_timezone_set($originalTimezone ?? 'UTC');
    }
}
```

- [ ] **Step 5: Add test for partial-success flash when thought creation fails**

Add this new test to `tests/Feature/EmailReviewInboxTest.php`:

```php
public function test_allow_action_shows_partial_success_flash_when_thought_creation_fails(): void
{
    // Simulate thought creation failing (no OpenRouter fake → HTTP call will fail)
    config(['services.openrouter.api_key' => 'test-key']);
    Http::fake([
        'https://openrouter.ai/api/v1/embeddings' => Http::response([], 500),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([], 500),
    ]);

    $user = User::factory()->create();
    ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertRedirect(route('inbox.index'));
    // Sender rule is saved — this part succeeded
    $this->assertDatabaseHas('email_sender_rules', [
        'user_id' => $user->id,
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);
    // Inbox item is done (was marked done by applySenderClassification)
    $inbox->refresh();
    $this->assertSame('done', $inbox->status);
    // Partial-success flash
    $response->assertSessionHas('success', 'Sender rule saved. Could not import email as a thought.');
    // No thought created
    $this->assertSame(0, Thought::query()->count());
}
```

- [ ] **Step 6: Run the updated/new tests to confirm they fail (they should, because the controller hasn't changed yet)**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/EmailReviewInboxTest.php --stop-on-failure
```

Expected: Several failures. The allow-creates-thought tests fail because the controller still doesn't create thoughts. The partial-success flash test fails because the controller returns a generic success, not the partial flash.

- [ ] **Step 7: Update `InboxController@applyEmailReviewAction` for the `allow` path**

In `app/Http/Controllers/InboxController.php`, replace the `applyEmailReviewAction` method:

```php
public function applyEmailReviewAction(Request $request, InboxItem $inboxItem, EmailReviewActionService $reviewActionService): RedirectResponse
{
    $this->authorize('update', $inboxItem);

    $validated = $request->validate([
        'action' => 'required|in:allow,ignore,extra_process,save_thought',
    ]);

    if ($validated['action'] === 'save_thought') {
        try {
            $reviewActionService->saveReviewedEmailAsThought($inboxItem, $request->user());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('inbox.index')->with('error', 'Unable to save inbox item as a thought.');
        }

        return redirect()->route('inbox.index')->with('success', 'Saved as thought.');
    }

    try {
        $applied = $reviewActionService->applySenderClassification($inboxItem, $request->user(), $validated['action']);
    } catch (\InvalidArgumentException $e) {
        report($e);

        return redirect()->route('inbox.index')->with('error', 'Unable to apply sender classification.');
    }

    if (! $applied) {
        return redirect()->route('inbox.index')->with('success', 'Sender classification was already handled.');
    }

    if ($validated['action'] === 'allow') {
        try {
            $reviewActionService->saveReviewedEmailAsThought($inboxItem, $request->user());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('inbox.index')->with('success', 'Sender rule saved. Could not import email as a thought.');
        }
    }

    return redirect()->route('inbox.index')->with('success', 'Sender classification saved.');
}
```

- [ ] **Step 8: Run the tests to confirm they pass**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/EmailReviewInboxTest.php
```

Expected: All tests pass (green).

- [ ] **Step 9: Run the full test suite to check for regressions**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test
```

Expected: All tests pass.

- [ ] **Step 10: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add app/Http/Controllers/InboxController.php tests/Feature/EmailReviewInboxTest.php
git commit -m "feat: allow action on email review items saves rule and imports thought"
```

---

## Chunk 2: Navigation link from inbox to sender rules settings

**Files:**
- Modify: `resources/views/inbox/index.blade.php`
- Modify: `tests/Feature/InboxPageTest.php`

---

- [ ] **Step 1: Write a failing test for the navigation link**

Add to `tests/Feature/InboxPageTest.php`:

```php
public function test_inbox_shows_manage_sender_rules_link_when_sender_policy_enabled(): void
{
    config(['services.email_sender_policy.enabled' => true]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('inbox.index'));

    $response->assertOk();
    $response->assertSee(route('settings.email-sender-rules.index'), false);
    $response->assertSee('Manage sender rules', false);
}

public function test_inbox_does_not_show_manage_sender_rules_link_when_sender_policy_disabled(): void
{
    config(['services.email_sender_policy.enabled' => false]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('inbox.index'));

    $response->assertOk();
    $response->assertDontSee('Manage sender rules', false);
}
```

- [ ] **Step 2: Run the new tests to confirm they fail**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/InboxPageTest.php --stop-on-failure
```

Expected: Both new tests fail (link not in view yet).

- [ ] **Step 3: Add the navigation link to the inbox view**

In `resources/views/inbox/index.blade.php`, find the `<div class="mb-8">` header block:

```blade
<div class="mb-8">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Inbox</h1>
    <p class="mt-2 text-sm text-slate-brand">Agent-generated prompts that need triage.</p>
</div>
```

Replace it with:

```blade
<div class="mb-8">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Inbox</h1>
    <p class="mt-2 text-sm text-slate-brand">Agent-generated prompts that need triage.</p>
    @if(config('services.email_sender_policy.enabled'))
        <a href="{{ route('settings.email-sender-rules.index') }}" class="mt-2 inline-block text-xs text-neural-teal hover:underline">Manage sender rules →</a>
    @endif
</div>
```

- [ ] **Step 4: Run the new tests to confirm they pass**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/InboxPageTest.php
```

Expected: All tests pass.

- [ ] **Step 5: Run the full test suite**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add resources/views/inbox/index.blade.php tests/Feature/InboxPageTest.php
git commit -m "feat: add manage sender rules link in inbox header"
```

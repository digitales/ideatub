# Inbox AJAX Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all `/inbox` actions update the page in place with AJAX while preserving the current server-rendered fallback behavior.

**Architecture:** Keep the current Blade inbox page and POST routes as the source of truth. Extend `InboxController` to return JSON for `expectsJson()` requests, then add a focused Alpine component on the inbox page that submits existing forms with `fetch`, removes successful cards in place, updates both inbox badge locations and the avatar aria-label, and falls back to reloading the current inbox URL when pagination makes the client state ambiguous.

**Tech Stack:** Laravel 12, Blade, Alpine.js, `fetch`, Tailwind CSS, Pest/PHPUnit-style Laravel feature tests, Vite.

**Spec:** `docs/superpowers/specs/2026-03-30-inbox-ajax-actions-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/InboxController.php` | Keep existing inbox actions, add `expectsJson()` branching, success/error JSON payloads, and a shared actionable-count helper |
| `resources/views/inbox/index.blade.php` | Add Alpine hooks, page-level success/error state, stable per-card identifiers, and AJAX-enhanced forms without breaking normal POST fallback |
| `resources/views/layouts/idea.blade.php` | Add stable hooks for both inbox badge locations and the avatar button label so JS can keep nav state synchronized |
| `resources/js/app.js` | Add a small `inboxPage()` Alpine component (and small helpers if needed) for AJAX submit, defensive response parsing, card removal, badge updates, fallback reloads, and UI messages |
| `tests/Feature/InboxActionsTest.php` | Add JSON-response coverage for standard inbox actions and save-as-thought failure/success cases |
| `tests/Feature/EmailReviewInboxTest.php` | Add JSON-response coverage for email review actions, repeated-action behavior, and partial-success/error branches |
| `tests/Feature/InboxPageTest.php` | Optionally extend server-rendered page assertions for any new stable hooks or accessibility attributes introduced in Blade |

---

## Task 1: Add failing JSON action tests for standard inbox actions

**Files:**
- Modify: `tests/Feature/InboxActionsTest.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Test: `tests/Feature/InboxActionsTest.php`

- [ ] **Step 1: Add a failing JSON success test for `done`**

Add a test like:

```php
public function test_done_action_returns_json_for_ajax_requests(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'dedupe_key' => 'done-json-item',
        'status' => 'pending',
        'snoozed_until' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('inbox.done', $item));

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Inbox item marked done.',
            'item_id' => $item->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 2: Run the new `done` JSON test and verify it fails**

Run: `php artisan test tests/Feature/InboxActionsTest.php --filter=done_action_returns_json_for_ajax_requests -v`

Expected: FAIL because `InboxController::markDone()` currently always redirects.

- [ ] **Step 3: Add a failing JSON success test for `snooze`**

Add a test like:

```php
public function test_snooze_action_returns_json_for_ajax_requests(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'dedupe_key' => 'snooze-json-item',
    ]);

    $response = $this->actingAs($user)->postJson(route('inbox.snooze', $item), [
        'preset' => 'tomorrow',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Inbox item snoozed.',
            'item_id' => $item->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 4: Add a failing JSON count-accuracy test with another actionable item still present**

Add a second pending actionable item for the same user and assert the count is not always `0`:

```php
public function test_done_action_json_returns_remaining_actionable_count(): void
{
    $user = User::factory()->create();
    $doneItem = InboxItem::factory()->create([
        'user_id' => $user->id,
        'dedupe_key' => 'done-json-count-item',
        'status' => 'pending',
        'snoozed_until' => null,
    ]);
    InboxItem::factory()->create([
        'user_id' => $user->id,
        'dedupe_key' => 'done-json-count-remaining-item',
        'status' => 'pending',
        'snoozed_until' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('inbox.done', $doneItem));

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'item_id' => $doneItem->id,
            'remaining_count' => 1,
        ]);
}
```

- [ ] **Step 5: Add a failing JSON validation-error test for invalid snooze preset**

Add a test like:

```php
public function test_invalid_snooze_preset_returns_json_validation_error_for_ajax_requests(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'dedupe_key' => 'snooze-json-invalid-item',
    ]);

    $response = $this->actingAs($user)->postJson(route('inbox.snooze', $item), [
        'preset' => 'later',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['preset']);
}
```

- [ ] **Step 6: Add a failing JSON success test for standard `save as thought`**

Use the existing OpenRouter fake helper and assert the JSON payload:

```php
public function test_save_as_thought_returns_json_for_ajax_requests(): void
{
    $this->fakeOpenRouterForThoughtCapture();

    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'title' => 'Turn this into a thought',
        'body' => 'Body content for the thought.',
        'dedupe_key' => 'save-thought-json-item',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('inbox.save-thought', $item));

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Saved as thought.',
            'item_id' => $item->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 7: Add a failing JSON error test for standard `save as thought` failure**

Mirror the existing redirect-based failure test, but assert JSON:

```php
public function test_save_as_thought_failure_returns_json_error_for_ajax_requests(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'title' => 'Will fail',
        'body' => 'Capture should fail.',
        'dedupe_key' => 'save-failure-json-item',
    ]);

    $this->mock(ThoughtCaptureService::class, function ($mock): void {
        $mock->shouldReceive('create')->andThrow(new \RuntimeException('capture failed'));
    });

    $response = $this->actingAs($user)
        ->postJson(route('inbox.save-thought', $item));

    $response->assertStatus(503)
        ->assertJson([
            'message' => 'Unable to save inbox item as a thought.',
        ]);
}
```

- [ ] **Step 8: Add a failing JSON authorization test for standard inbox actions**

Reuse the existing ownership scenario, but request JSON:

```php
public function test_user_cannot_mutate_another_users_inbox_item_with_json_request(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $owner->id,
        'dedupe_key' => 'forbidden-json-item',
    ]);

    $this->actingAs($other)
        ->postJson(route('inbox.done', $item))
        ->assertForbidden();
}
```

- [ ] **Step 9: Run the focused standard inbox action tests and verify they fail**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: FAIL in the new JSON assertions while existing redirect-based tests still pass.

- [ ] **Step 10: Implement JSON responses in `InboxController` for standard inbox actions**

Update `markDone()`, `snooze()`, and `saveAsThought()` to:

1. Keep the existing service behavior.
2. Branch on `$request->expectsJson()`.
3. Return:

```php
return response()->json([
    'ok' => true,
    'message' => 'Inbox item marked done.',
    'item_id' => $inboxItem->id,
    'remaining_count' => $this->actionableInboxCountFor($request->user()),
]);
```

For failures in `saveAsThought()`:

```php
if ($request->expectsJson()) {
    return response()->json([
        'message' => 'Unable to save inbox item as a thought.',
    ], 503);
}
```

Add a small private helper in the controller:

```php
private function actionableInboxCountFor(User $user): int
{
    return InboxItem::query()
        ->forUser($user)
        ->actionable()
        ->count();
}
```

Also update return types from `RedirectResponse` to `RedirectResponse|\Illuminate\Http\JsonResponse` (or import `JsonResponse` and use a union).

- [ ] **Step 11: Run the focused standard inbox action tests and verify they pass**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: PASS for both the legacy redirect tests and the new JSON tests.

- [ ] **Step 12: Commit the standard inbox controller/test changes**

Run:

```bash
git add tests/Feature/InboxActionsTest.php app/Http/Controllers/InboxController.php
git commit -m "feat: add json inbox action responses"
```

---

## Task 2: Add failing JSON action tests for email review inbox flows

**Files:**
- Modify: `tests/Feature/EmailReviewInboxTest.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`

- [ ] **Step 1: Add a failing JSON success test for email-review `allow`**

Add a test like:

```php
public function test_allow_email_review_action_returns_json_for_ajax_requests(): void
{
    $this->fakeOpenRouterForThoughtCapture();

    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Sender classification saved.',
            'item_id' => $inbox->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 2: Add a failing JSON success test for email-review `ignore`**

Add:

```php
public function test_ignore_email_review_action_returns_json_for_ajax_requests(): void
{
    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'ignore',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Sender classification saved.',
            'item_id' => $inbox->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 3: Add a failing JSON success test for email-review `extra_process`**

Add:

```php
public function test_extra_process_email_review_action_returns_json_for_ajax_requests(): void
{
    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'extra_process',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Sender classification saved.',
            'item_id' => $inbox->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 4: Add a failing JSON count-accuracy test for email-review actions**

Keep another actionable inbox item around and assert the returned count stays accurate:

```php
public function test_email_review_action_json_returns_remaining_actionable_count(): void
{
    $this->fakeOpenRouterForThoughtCapture();

    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);
    InboxItem::factory()->create([
        'user_id' => $user->id,
        'generator_type' => 'weekly_revisit',
        'dedupe_key' => 'email-review-json-count-remaining-item',
        'status' => 'pending',
        'snoozed_until' => null,
    ]);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'item_id' => $inbox->id,
            'remaining_count' => 1,
        ]);
}
```

- [ ] **Step 5: Add a failing JSON success test for repeat classification**

Mirror the “already handled” redirect test with JSON:

```php
public function test_repeat_classification_returns_json_already_handled_message(): void
{
    $this->fakeOpenRouterForThoughtCapture();
    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'ignore',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Sender classification was already handled.',
            'item_id' => $inbox->id,
        ]);
}
```

- [ ] **Step 6: Add a failing JSON success test for email-review `save_thought`**

Add a test like:

```php
public function test_email_review_save_as_thought_returns_json_for_ajax_requests(): void
{
    $this->fakeOpenRouterForThoughtCapture();

    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'save_thought',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Saved as thought.',
            'item_id' => $inbox->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 7: Add a failing JSON error test for email-review `save_thought` failure**

Mirror the existing email-review save path but force the reviewed-email thought creation to fail:

```php
public function test_email_review_save_as_thought_failure_returns_json_error_for_ajax_requests(): void
{
    config(['services.openrouter.api_key' => 'test-key']);
    Http::fake([
        'https://openrouter.ai/api/v1/embeddings' => Http::response([], 500),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([], 500),
    ]);

    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'save_thought',
    ]);

    $response->assertStatus(503)
        ->assertJson([
            'message' => 'Unable to save inbox item as a thought.',
        ]);
}
```

- [ ] **Step 8: Add a failing JSON partial-success test for `allow` when thought creation fails**

Mirror the existing partial-success redirect test:

```php
public function test_allow_action_returns_json_partial_success_when_thought_creation_fails(): void
{
    config(['services.openrouter.api_key' => 'test-key']);
    Http::fake([
        'https://openrouter.ai/api/v1/embeddings' => Http::response([], 500),
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([], 500),
    ]);

    $user = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'Sender rule saved. Could not import email as a thought.',
            'item_id' => $inbox->id,
            'remaining_count' => 0,
        ]);
}
```

- [ ] **Step 9: Add a failing JSON error test for invalid sender classification**

Mirror the existing mismatched sender failure branch but with JSON:

```php
public function test_invalid_email_review_sender_returns_json_error_for_ajax_requests(): void
{
    $user = User::factory()->create();
    ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    $inbox->update([
        'source_data' => [
            'stored_email_type' => 'imported_email',
            'stored_email_id' => $imported->id,
            'sender_email' => 'Wrong Sender <WRONG@example.com>',
            'rule_action' => 'review',
        ],
    ]);

    $response = $this->actingAs($user)->postJson(route('inbox.email-review.action', $inbox), [
        'action' => 'allow',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Unable to apply sender classification.',
        ]);
}
```

- [ ] **Step 10: Add a failing JSON authorization test for email review actions**

Add:

```php
public function test_user_cannot_apply_email_review_action_with_json_request_to_another_users_item(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ['inbox' => $inbox] = $this->createImportedEmailReviewFixture($owner);

    $this->actingAs($other)
        ->postJson(route('inbox.email-review.action', $inbox), ['action' => 'allow'])
        ->assertForbidden();
}
```

- [ ] **Step 11: Run the focused email review tests and verify they fail**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php -v`

Expected: FAIL because `applyEmailReviewAction()` currently always redirects.

- [ ] **Step 12: Implement JSON responses in `InboxController` for email review actions**

Update `applyEmailReviewAction()` so every existing branch can return JSON when `$request->expectsJson()` is true:

- `save_thought` success => JSON `ok/message/item_id/remaining_count`
- `save_thought` failure => `503` JSON with `message`
- `ignore` success => `200` JSON success payload with `Sender classification saved.`
- `extra_process` success => `200` JSON success payload with `Sender classification saved.`
- classification invalid => `422` JSON with `message`
- already handled => `200` JSON success payload with the “already handled” message
- `allow` partial-success branch => `200` JSON success payload with the partial-success message
- standard classification success => `200` JSON success payload with `Sender classification saved.`

Use the same `actionableInboxCountFor()` helper added in Task 1.

- [ ] **Step 13: Run the focused email review tests and verify they pass**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php -v`

Expected: PASS for the existing redirect-based tests and the new JSON tests.

- [ ] **Step 14: Commit the email review controller/test changes**

Run:

```bash
git add tests/Feature/EmailReviewInboxTest.php app/Http/Controllers/InboxController.php
git commit -m "feat: add json email review inbox responses"
```

---

## Task 3: Add the inbox Alpine component and Blade hooks

**Files:**
- Modify: `resources/js/app.js`
- Modify: `resources/views/inbox/index.blade.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Test: manual verification against `/inbox`

- [ ] **Step 1: Add stable DOM hooks in the layout and inbox page**

Update `resources/views/layouts/idea.blade.php`:

- add a stable selector or shared `data-inbox-badge` attribute to both badge locations
- add a stable hook on the avatar button for updating its `aria-label`
- preserve the existing server-rendered badge visibility behavior when `$inboxCount <= 0`

For example:

```blade
<button
    data-inbox-avatar-button
    aria-label="{{ $accountMenuLabel }}"
    ...
>
```

```blade
<span data-inbox-badge="avatar" data-testid="avatar-inbox-badge" ...>
```

```blade
<span data-inbox-badge="menu" data-testid="account-menu-inbox-badge" ...>
```

Update `resources/views/inbox/index.blade.php`:

- add a page-level Alpine root, such as `x-data="inboxPage()"`
- add stable item wrappers, such as `data-inbox-item-id="{{ $item->id }}"`
- add containers for transient success and error messages
- use the full current inbox URL, including query string, for fallback reloads (for example `window.location.href`, not just `window.location.pathname`)

- [ ] **Step 2: Run a quick server-rendered inbox page test if you add new rendered hooks**

If you introduce new visible markup guarantees, add or extend a page test in `tests/Feature/InboxPageTest.php`, then run:

`php artisan test tests/Feature/InboxPageTest.php -v`

Expected: PASS once the Blade changes are rendered correctly.

- [ ] **Step 3: Add a failing Alpine enhancement in `resources/js/app.js`**

Model the new component after the existing `captureBox()` and `thoughtCardActions()` patterns:

```js
Alpine.data('inboxPage', () => ({
  message: '',
  messageType: 'success',
  submittingItemId: null,

  get csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  },

  async submitAction(event) {
    // minimal first draft
  },
}));
```

Wire forms in Blade with:

```blade
<form
    method="POST"
    action="{{ route('inbox.done', $item) }}"
    @submit.prevent="submitAction($event)"
>
```

Expected at this stage: build may pass, but browser behavior is not complete yet.

- [ ] **Step 4: Implement defensive AJAX submission**

Inside `submitAction(event)`:

1. Resolve the form and nearest card element.
2. Build `FormData(form)`.
3. Submit with:

```js
const res = await fetch(form.action, {
  method: form.method || 'POST',
  body,
  redirect: 'follow',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
  },
});
```

4. Check `res.headers.get('content-type')` before attempting JSON parse:

```js
const contentType = res.headers.get('content-type') || '';
const isJson = contentType.includes('application/json');
const data = isJson ? await res.json().catch(() => ({})) : {};
```

5. If the response is successful but not JSON, reload the current inbox URL:

```js
window.location = this.currentUrl();
return;
```

Use the same defensive style described in `support/2026-03-13-store-thought-button-not-functioning-after-ajax-change.md`.

For pagination fallback, reload the full current inbox URL including `?page=` and any future query params:

```js
currentUrl() {
  return window.location.href;
}
```

- [ ] **Step 5: Implement success behavior**

On successful JSON response:

- clear any previous error
- show `data.message`
- remove the matching `[data-inbox-item-id]` card
- update both nav badge locations from `data.remaining_count`
- update the avatar `aria-label` using the same three-branch copy already rendered in Blade:
  - `Account menu`
  - `Account menu, inbox has N actionable item` when count is `1`
  - `Account menu, inbox has N actionable items` when count is between `2` and `99`
  - `Account menu, inbox has more than 99 actionable items` when count exceeds `99`

Implement a small helper like:

```js
updateInboxBadge(count) {
  const text = count > 99 ? '99+' : String(count);
  document.querySelectorAll('[data-inbox-badge]').forEach((badge) => {
    if (count <= 0) badge.remove();
    else badge.textContent = text;
  });
}
```

Document this behavior in code comments or the component: removing the badges when the count reaches `0` matches the current Blade layout, and the badges will only reappear on a later full server render.

If the current page has no remaining visible cards after removal, reload the current inbox URL so the server can render the correct paginated or empty-state result.

- [ ] **Step 6: Implement failure behavior**

On failed responses:

- keep the card in place
- re-enable the clicked button
- show a friendly error message

Use the same broad error handling style as other Alpine components:

```js
if (res.status === 419) this.message = 'Session expired. Please refresh the page and try again.';
else if (res.status === 401 || res.status === 403) this.message = 'Please sign in again.';
else if (res.status === 422 && data.errors) this.message = Object.values(data.errors).flat()[0] || data.message || 'Unable to apply this inbox action.';
else if (res.status === 422) this.message = data.message || 'Unable to apply this inbox action.';
else this.message = data.message || 'Something went wrong. Please try again.';
this.messageType = 'error';
```

- [ ] **Step 7: Limit loading state to the clicked button**

Track both item ID and button/form identity so only the clicked button is disabled/spun while the request is active.

One straightforward Blade pattern is:

```blade
<button
    type="submit"
    :disabled="isSubmitting({{ $item->id }}, 'done')"
    ...
>
```

with Alpine helpers:

```js
activeItemId: null,
activeActionKey: '',
isSubmitting(itemId, actionKey) {
  return this.activeItemId === itemId && this.activeActionKey === actionKey;
},
```

- [ ] **Step 8: Build the frontend assets and fix any JS errors**

Run: `npm run build`

Expected: PASS with no syntax or bundling errors in `resources/js/app.js`.

- [ ] **Step 9: Manually verify the inbox UX in the browser**

Verify:

- standard actions update in place
- email review actions update in place
- the acted-on card disappears on success
- only the clicked button is disabled during submission
- both nav badges stay synchronized
- the avatar `aria-label` updates
- when the current page becomes empty, the inbox reloads into the correct paginated or empty result
- failed requests leave the card visible and show an error
- with JavaScript disabled, normal form POST + redirect still works

- [ ] **Step 10: Commit the frontend inbox AJAX changes**

Run:

```bash
git add resources/js/app.js resources/views/inbox/index.blade.php resources/views/layouts/idea.blade.php tests/Feature/InboxPageTest.php
git commit -m "feat: ajaxify inbox actions"
```

Only include `tests/Feature/InboxPageTest.php` in the commit if it changed.

---

## Task 4: Run full verification and clean up

**Files:**
- Modify: any files touched in prior tasks if fixes are needed
- Test: `tests/Feature/InboxActionsTest.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`
- Test: `tests/Feature/InboxPageTest.php`

- [ ] **Step 1: Run the focused inbox feature suite**

Run:

```bash
php artisan test tests/Feature/InboxActionsTest.php tests/Feature/EmailReviewInboxTest.php tests/Feature/InboxPageTest.php -v
```

Expected: PASS.

- [ ] **Step 2: Read lint errors for changed files and fix any introduced issues**

Check changed files for diagnostics, then fix any new problems in:

- `app/Http/Controllers/InboxController.php`
- `resources/views/inbox/index.blade.php`
- `resources/views/layouts/idea.blade.php`
- `resources/js/app.js`

- [ ] **Step 3: Do one final browser sanity pass on `/inbox`**

Re-verify the key happy path and one failure path after the full test run so the final implementation matches the spec.

- [ ] **Step 4: Commit any final verification fixes**

Run:

```bash
git add app/Http/Controllers/InboxController.php resources/views/inbox/index.blade.php resources/views/layouts/idea.blade.php resources/js/app.js tests/Feature/InboxActionsTest.php tests/Feature/EmailReviewInboxTest.php tests/Feature/InboxPageTest.php
git commit -m "test: finalize inbox ajax actions coverage"
```

If no verification fixes were needed after the previous commits, skip this commit.

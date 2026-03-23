# Email View Sender Rule Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add sender-rule management to the email thought detail page so users can whitelist the current sender quickly, remove a whitelist rule, and manage the full sender action inline without leaving the page.

**Architecture:** Extend the existing thought detail flow so `IdeaController@show` loads sender-rule context for email thoughts behind the existing sender-policy feature flag. Keep mutation logic out of `IdeaController` by adding a thought-scoped controller and routes that derive the sender from trusted stored-email data, using shared sender-resolution logic aligned with the existing email review workflow.

**Tech Stack:** Laravel 12, Blade, Pest/PHPUnit feature tests, existing `EmailSenderRule`, `ImportedEmail`, `CapturedInboundEmail`, and `EmailSenderRuleService`

---

## File Structure

- Create: `app/Http/Controllers/EmailThoughtSenderRuleController.php`  
  Sender-rule mutations scoped to a thought detail page (`POST` upsert, `DELETE` remove), feature-flag gated, `update`-authorized.

- Create: `app/Services/Email/ThoughtEmailSenderContextResolver.php`  
  Resolve the stored email backing an email thought, derive the normalized sender, and load the current sender rule. This keeps sender selection logic out of controllers and views.

- Create: `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php`  
  Compact sender-rule UI used by the email sidebar.

- Create: `tests/Feature/EmailThoughtSenderRuleManagementTest.php`  
  End-to-end coverage for page-specific sender-rule routes and card behavior that does not fit cleanly in the existing thought show page test file.

- Modify: `app/Http/Controllers/IdeaController.php`  
  Load sender-rule context in `show()` for email thoughts when the feature flag is enabled.

- Modify: `resources/views/idea/show.blade.php`  
  Pass sender-rule context into the email sidebar include.

- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`  
  Render the sender-rule card (or unavailable message) beneath the existing metadata.

- Modify: `routes/web.php`  
  Add thought-scoped sender-rule `POST`/`DELETE` routes next to `thoughts.show`.

- Modify: `tests/Feature/ThoughtShowPageTest.php`  
  Keep basic thought-show rendering coverage close to the existing email detail assertions.

- Reference: `app/Models/ImportedEmail.php`  
  Imported-email-backed thought detail cases.

- Reference: `app/Models/CapturedInboundEmail.php`  
  Postmark-backed thought detail cases that do not flow through `Thought::importedEmail()`.

- Reference: `app/Services/Email/EmailReviewActionService.php`  
  Match sender-resolution precedence for stored email records and avoid inventing a third independent sender-selection path.

- Reference: `docs/superpowers/specs/2026-03-22-email-view-sender-rule-management-design.md`  
  Source of truth for UX, flag behavior, and sender-resolution rules.

## Task 1: Load Sender-Rule Context On The Email Thought Page

**Files:**
- Create: `app/Services/Email/ThoughtEmailSenderContextResolver.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Write the failing thought-show tests for sender-rule context**

Add focused tests to `tests/Feature/ThoughtShowPageTest.php` for:
- imported-email-backed thought shows `Whitelist sender` when no rule exists
- captured-inbound-email-backed thought shows the sender-rule card
- page shows the current rule state when a rule exists
- feature flag disabled hides the card
- unresolved sender shows the unavailable message instead of controls

Name the new tests with a `sender_rule` substring so the filter commands below run the intended subset.

Example imported-email test shape:

```php
public function test_email_thought_detail_sender_rule_shows_whitelist_sender_for_imported_email_without_rule(): void
{
    config(['services.email_sender_policy.enabled' => true]);

    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'email',
    ]);

    $account = MailAccount::factory()->create(['user_id' => $user->id]);
    $importedEmail = ImportedEmail::create([
        'user_id' => $user->id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'sender-card-1',
        'direction' => 'received',
        'from_json' => [['email' => 'sender@example.com', 'name' => 'Sender']],
        'processing_status' => 'imported',
        'thought_id' => $thought->id,
    ]);

    $thought->update(['source_metadata' => ['imported_email_id' => $importedEmail->id]]);

    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertSee('Sender rule')
        ->assertSee('sender@example.com')
        ->assertSee('Whitelist sender');
}
```

Example captured-inbound test shape:

```php
public function test_email_thought_detail_sender_rule_card_renders_for_captured_inbound_email(): void
{
    config(['services.email_sender_policy.enabled' => true]);

    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'email',
        'source_metadata' => [
            'captured_inbound_email_id' => 1,
        ],
    ]);

    $captured = CapturedInboundEmail::query()->create([
        'id' => 1,
        'user_id' => $user->id,
        'message_id' => 'msg-captured-card',
        'sender_email' => 'postmark-sender@example.com',
        'subject' => 'Captured subject',
        'body_text' => 'Captured body',
        'received_at' => now(),
        'rule_action' => 'review',
        'rule_email' => 'postmark-sender@example.com',
        'thought_id' => $thought->id,
        'processing_status' => 'imported',
    ]);

    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertSee('Sender rule')
        ->assertSee('postmark-sender@example.com');
}
```

- [ ] **Step 2: Run the targeted thought-show tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=sender_rule
```

Expected:
- FAIL because the sender-rule card/context does not exist yet

- [ ] **Step 3: Implement a resolver that loads stored email + normalized sender + current rule**

Create `app/Services/Email/ThoughtEmailSenderContextResolver.php` with one clear responsibility: given a `Thought`, return a small array/object containing:
- backing stored email type (`imported_email` or `captured_inbound_email`)
- stored email id
- raw sender
- normalized sender
- current `EmailSenderRule` model or `null`
- whether sender management is available

The resolver should:
- short-circuit when the thought is not `source === 'email'`
- short-circuit when `services.email_sender_policy.enabled` is off
- not invent a standalone sender-selection algorithm; first inspect `EmailReviewActionService` and either reuse or extract shared sender-resolution helpers for stored email rows
- identify backing email rows in this exact order:
  1. if the thought points to an `ImportedEmail`, use that row
  2. else if the thought points to a `CapturedInboundEmail`, use that row
  3. only if neither stored-email row is available, fall back to `source_metadata`
- follow the spec’s sender precedence:
  - `ImportedEmail`: `rule_email` -> formatted `from_json` -> `source_metadata.from`
  - `CapturedInboundEmail`: `rule_email` -> `sender_email` -> `source_metadata.from`
- handle `source_metadata.from` as either a participant array or a plain string
- normalize with `EmailSenderRuleService::normalizeSender()`

Minimal implementation sketch:

```php
final class ThoughtEmailSenderContextResolver
{
    public function resolve(Thought $thought): array
    {
        if ($thought->source !== 'email' || ! config('services.email_sender_policy.enabled')) {
            return ['available' => false];
        }

        // look up ImportedEmail or CapturedInboundEmail
        // choose raw sender with review-aligned precedence
        // normalize sender
        // load existing EmailSenderRule for $thought->user_id
    }
}
```

- [ ] **Step 4: Wire the resolver into `IdeaController@show` and render the sidebar card**

Update `IdeaController@show()` to inject/use the resolver and pass `senderRuleContext` into the view.

Add a dedicated partial `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php` and include it from `thought_detail_email_sidebar.blade.php` so the existing metadata block stays readable.

Render states:
- feature enabled + sender available + no rule => `Whitelist sender`
- feature enabled + sender available + rule exists => current action label + controls
- feature enabled + sender unavailable => unavailable message
- feature disabled => no sender-rule UI

- [ ] **Step 5: Re-run the targeted thought-show tests and verify they pass**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=sender_rule
```

Expected:
- PASS for the new sender-rule thought-show assertions

- [ ] **Step 6: Run the full thought-show feature file**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php
```

Expected:
- PASS with no regressions to existing thought detail behavior

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/IdeaController.php app/Services/Email/ThoughtEmailSenderContextResolver.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php resources/views/idea/partials/thought_detail_sender_rule_card.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: load sender rule context on email thoughts"
```

## Task 2: Add Thought-Scoped Sender-Rule Mutation Endpoints

**Files:**
- Create: `app/Http/Controllers/EmailThoughtSenderRuleController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php`
- Modify: `app/Services/Email/ThoughtEmailSenderContextResolver.php`
- Create: `tests/Feature/EmailThoughtSenderRuleManagementTest.php`
- Test: `tests/Feature/EmailThoughtSenderRuleManagementTest.php`

- [ ] **Step 1: Write failing feature tests for create/update/delete sender rules from the thought page**

Create `tests/Feature/EmailThoughtSenderRuleManagementTest.php` covering:
- quick whitelist creates `allow`
- whitelist updates a non-allow rule to `allow`
- remove from whitelist deletes an existing `allow` rule
- full-rule save sets `ignore`, `review`, and `extra_process`
- all upserts go through the same `POST` endpoint with validated `action`
- wrong user gets `403`
- non-email thought gets `404`
- feature flag off gets `404`
- unresolved sender redirects back with an error flash and does not mutate rules

Example shape:

```php
public function test_user_can_whitelist_sender_from_email_thought_page(): void
{
    config(['services.email_sender_policy.enabled' => true]);

    $user = User::factory()->create();
    $thought = $this->createImportedEmailThought($user, 'sender@example.com');

    $response = $this->actingAs($user)->post(
        route('thoughts.sender-rules.store', $thought),
        ['action' => EmailSenderRule::ACTION_ALLOW]
    );

    $response->assertRedirect(route('thoughts.show', $thought));
    $this->assertDatabaseHas('email_sender_rules', [
        'user_id' => $user->id,
        'sender_email' => 'sender@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);
}
```

- [ ] **Step 2: Run the new feature file and verify it fails**

Run:

```bash
php artisan test tests/Feature/EmailThoughtSenderRuleManagementTest.php
```

Expected:
- FAIL because the routes/controller/forms do not exist yet

- [ ] **Step 3: Add thought-scoped routes and implement the controller**

Add routes near `thoughts.show` in `routes/web.php`, for example:

```php
Route::post('/thoughts/{thought}/sender-rule', [EmailThoughtSenderRuleController::class, 'store'])
    ->name('thoughts.sender-rules.store');
Route::delete('/thoughts/{thought}/sender-rule', [EmailThoughtSenderRuleController::class, 'destroy'])
    ->name('thoughts.sender-rules.destroy');
```

Implement `EmailThoughtSenderRuleController` so it:
- returns `404` when `services.email_sender_policy.enabled` is off
- authorizes `update` on the thought
- returns `404` for non-email thoughts
- resolves sender context using `ThoughtEmailSenderContextResolver`
- validates `action` with `Rule::in(EmailSenderRule::actions())`
- uses `updateOrCreate()` for create/update
- deletes the sender rule row on `destroy()`
- redirects back to `route('thoughts.show', $thought)` with success/error flashes

- [ ] **Step 4: Wire forms into the sender-rule card**

Update `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php` to include:
- quick `Whitelist sender` form posting `action=allow`
- quick `Remove from whitelist` delete form when current action is `allow`
- compact full-rule form with action select and save button
- remove action for any existing rule

Keep the card compact and sender-specific. Do not duplicate the settings page list UI.
Use standard Laravel form conventions for delete actions (`@csrf` + `@method('DELETE')`).

- [ ] **Step 5: Run the new feature file and verify it passes**

Run:

```bash
php artisan test tests/Feature/EmailThoughtSenderRuleManagementTest.php
```

Expected:
- PASS for all thought-scoped sender-rule management behavior

- [ ] **Step 6: Run both affected feature files together**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/EmailThoughtSenderRuleManagementTest.php
```

Expected:
- PASS with sender-rule card rendering and route mutations working together

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/EmailThoughtSenderRuleController.php app/Services/Email/ThoughtEmailSenderContextResolver.php resources/views/idea/partials/thought_detail_sender_rule_card.blade.php routes/web.php tests/Feature/EmailThoughtSenderRuleManagementTest.php
git commit -m "feat: manage sender rules from email thoughts"
```

## Task 3: Final Verification And Polish

**Files:**
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Modify: `tests/Feature/EmailThoughtSenderRuleManagementTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/EmailThoughtSenderRuleManagementTest.php`

- [ ] **Step 1: Review the UI states for duplication and confusing actions**

Check that the card does not:
- show both `Whitelist sender` and a duplicate `allow` save affordance in a confusing way
- expose mutation controls when sender resolution failed
- render when the feature flag is off

If needed, tighten labels and conditional rendering.

- [ ] **Step 2: Add or refine any missing regression tests discovered during review**

Likely additions:
- quick whitelist button still appears when the current rule is `ignore` or `review`
- existing `allow` rule shows `Remove from whitelist`
- captured-inbound thought with string `source_metadata.from` fallback remains safe

- [ ] **Step 3: Run lint/diagnostics for the touched files**

Run IDE diagnostics and fix any newly introduced issues.

Suggested checks:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/EmailThoughtSenderRuleManagementTest.php
./vendor/bin/pint --dirty
```

Expected:
- PASS, no new PHP test failures

- [ ] **Step 4: Sanity-check broader sender-rule coverage**

Run the existing sender-rule settings and inbox-review feature files to ensure the new page-specific flow did not regress the current sender-rule system.

Run:

```bash
php artisan test tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/EmailReviewInboxTest.php
```

Expected:
- PASS, proving the existing global settings and inbox flows still work

- [ ] **Step 5: Commit**

```bash
git add resources/views/idea/partials/thought_detail_email_sidebar.blade.php resources/views/idea/partials/thought_detail_sender_rule_card.blade.php tests/Feature/ThoughtShowPageTest.php tests/Feature/EmailThoughtSenderRuleManagementTest.php
git commit -m "test: cover email thought sender rule management"
```

## Notes For Execution

- Follow `@superpowers/test-driven-development` strictly: write each failing test first, run it, then add the minimal code to pass.
- Prefer the new resolver service over adding sender-resolution logic directly inside Blade templates or controllers.
- Keep the settings controller untouched unless a shared helper extraction genuinely requires it.
- If the resolver needs tiny private helpers for `ImportedEmail`, `CapturedInboundEmail`, and `source_metadata.from`, keep them inside the resolver rather than scattering them across the controller and view.

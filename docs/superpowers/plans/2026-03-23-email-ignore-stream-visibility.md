# Email Ignore Stream Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide email thoughts from ignored senders across stream-style thought listings, reconcile existing thoughts in the background when sender rules change, and restore visibility automatically when a sender is no longer ignored.

**Architecture:** Add explicit stream-visibility state to `thoughts`, then enforce it through a shared `Thought` query scope used by recent/index, stream, email stream, search, and realtime surfaces. Reuse sender-rule normalization via a dedicated email-thought visibility service plus a queued reconciliation job so rule changes update existing email thoughts safely without overloading delete semantics or blocking the request path.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, Pest/PHPUnit feature + unit tests, queues/jobs, existing `Thought`, `ImportedEmail`, `CapturedInboundEmail`, `EmailSenderRule`, `EmailSenderRuleService`, and email import/webhook services

---

## File Structure

- Create: `database/migrations/2026_03_23_000001_add_stream_visibility_to_thoughts_table.php`  
  Add `is_visible_in_stream` and `visibility_reason` to `thoughts` with safe defaults for existing rows.

- Create: `app/Services/Email/EmailThoughtStreamVisibilityService.php`  
  Centralize sender-based visibility decisions and applying hidden/visible state to thoughts created by email flows.

- Create: `app/Services/Email/ThoughtEmailSenderResolver.php`  
  Resolve the normalized sender for an email thought from `ImportedEmail`, `CapturedInboundEmail`, or the approved metadata fallback in the same precedence as the sender-rule system.

- Create: `app/Jobs/ReconcileIgnoredSenderThoughtVisibility.php`  
  Idempotent queued job that hides or restores existing email thoughts for one `(user_id, sender_email)` pair.

- Create: `app/Console/Commands/BackfillIgnoredSenderThoughtVisibilityCommand.php`  
  One-time rollout command that fans out reconciliation jobs for all existing ignored sender rules.

- Create: `tests/Feature/EmailThoughtStreamVisibilityTest.php`  
  End-to-end route coverage for hidden email thoughts across index, stream, email stream, search, realtime, and direct detail access.

- Create: `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php`  
  Coverage for the reconciliation job and rule-change dispatch behavior.

- Create: `tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php`  
  Coverage for the rollout command and job fan-out behavior.

- Modify: `app/Models/Thought.php`  
  Add fillable fields/constants/query scope for visible-in-stream filtering without applying it to direct detail reads.

- Modify: `app/Http/Controllers/IdeaController.php`  
  Apply the shared visible-in-stream scope to recent thoughts and stream-style list queries, but not `show()`.

- Modify: `app/Http/Controllers/Api/RealtimeCheckController.php`  
  Ignore hidden email thoughts when deciding whether the stream has new content.

- Modify: `app/Services/ThoughtSearchService.php`  
  Exclude hidden email thoughts from tag/semantic search results shown on `idea.index?q=`.

- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`  
  Keep hidden email thoughts out of authenticated API recent/search/stats responses that browse or count thoughts directly.

- Modify: `app/Http/Controllers/Api/McpController.php`  
  Keep hidden email thoughts out of MCP `browse_recent` and `thought_stats`, and align MCP browse/count behavior with the web/API read surfaces.

- Modify: `app/Http/Controllers/EmailSenderRuleSettingsController.php`  
  Dispatch reconciliation when rules are created, updated, or deleted.

- Modify: `app/Services/Email/EmailReviewActionService.php`  
  Dispatch reconciliation after sender classification changes and apply visibility immediately when a stale review inbox item still creates a thought after the sender is now ignored.

- Modify: `app/Services/Email/EmailImportService.php`  
  Keep current `ignore` short-circuit behavior, but route any created email thought through the visibility service so sender-based visibility stays centralized.

- Modify: `app/Services/PostmarkInboundService.php`  
  Mirror the same visibility-application behavior for Postmark-backed email thoughts.

- Modify: `tests/Feature/EmailSenderRuleSettingsTest.php`  
  Assert sender-rule settings actions dispatch reconciliation jobs.

- Modify: `tests/Feature/EmailReviewInboxTest.php`  
  Assert inbox-based ignore/allow changes dispatch reconciliation and that stale review-save flows create hidden thoughts when the sender is now ignored.

- Modify: `tests/Feature/ThoughtsApiTest.php`  
  Assert hidden email thoughts stay out of authenticated API recent/search/stats responses.

- Modify: `tests/Feature/McpApiTest.php`  
  Assert hidden email thoughts stay out of MCP browse/count surfaces.

- Modify: `tests/Unit/Services/EmailImportServiceTest.php`  
  Keep existing ignore-short-circuit coverage green and add visibility assertions for newly created thoughts that still come through the import service.

- Modify: `tests/Feature/PostmarkInboundWebhookTest.php`  
  Add visibility assertions for thoughts created by Postmark-backed sender-policy flows.

- Reference: `docs/superpowers/specs/2026-03-23-email-ignore-stream-visibility-design.md`  
  Source of truth for visibility semantics, rollout backfill, feature-flag behavior, and direct-detail exceptions.

## Task 1: Add Stream Visibility Fields And Read-Side Filtering

**Files:**
- Create: `database/migrations/2026_03_23_000001_add_stream_visibility_to_thoughts_table.php`
- Create: `tests/Feature/EmailThoughtStreamVisibilityTest.php`
- Modify: `app/Models/Thought.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/Http/Controllers/Api/RealtimeCheckController.php`
- Modify: `app/Services/ThoughtSearchService.php`
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/EmailThoughtStreamVisibilityTest.php`
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`
- Test: `tests/Feature/ThoughtTypePagesTest.php`
- Test: `tests/Feature/SearchIncludeTagsTest.php`
- Test: `tests/Feature/ThoughtsApiTest.php`
- Test: `tests/Feature/McpApiTest.php`

- [ ] **Step 1: Write the failing read-side visibility tests**

Create `tests/Feature/EmailThoughtStreamVisibilityTest.php` with focused cases for:
- hidden email thought is absent from `route('idea.index')`
- hidden email thought is absent from `route('idea.stream')`
- hidden email thought is absent from `route('idea.stream.emails')`
- hidden email thought is absent from `route('idea.index', ['q' => ...])`
- hidden email thought does not trigger `route('api.thoughts.realtime-check')`
- hidden email thought remains accessible at `route('thoughts.show', $thought)`
- visible non-email thought still appears normally beside hidden email rows
- hidden email thought stays filtered from web listings even when `services.email_sender_policy.enabled` is `false`

Extend existing API tests during this task:
- `tests/Feature/ThoughtsApiTest.php`: hidden email thought is excluded from `/api/thoughts/recent`, `/api/thoughts/search`, and `/api/thoughts/stats`
- `tests/Feature/McpApiTest.php`: hidden email thought is excluded from MCP `browse_recent` output and `thought_stats`

Example shape:

```php
public function test_hidden_email_thought_is_excluded_from_recent_stream_and_search_but_still_has_detail_page(): void
{
    $user = User::factory()->create();

    $hiddenEmail = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Hidden ignored sender email',
        'source' => 'email',
        'is_visible_in_stream' => false,
        'visibility_reason' => 'ignored_sender',
    ]);

    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Visible web thought',
        'source' => 'web',
    ]);

    $this->actingAs($user)->get(route('idea.index'))
        ->assertOk()
        ->assertDontSee('Hidden ignored sender email')
        ->assertSee('Visible web thought');

    $this->actingAs($user)->get(route('thoughts.show', $hiddenEmail))
        ->assertOk()
        ->assertSee('Hidden ignored sender email');
}
```

- [ ] **Step 2: Run the new visibility feature file and verify it fails**

Run:

```bash
php artisan test tests/Feature/EmailThoughtStreamVisibilityTest.php
```

Expected:
- FAIL with missing columns and/or hidden email thoughts still appearing in list and search responses

- [ ] **Step 3: Add the schema, constants, and shared visible-in-stream scope**

Create `database/migrations/2026_03_23_000001_add_stream_visibility_to_thoughts_table.php` to add:
- `is_visible_in_stream` boolean default `true`
- `visibility_reason` nullable string

Update `app/Models/Thought.php` to:
- add the new fields to `$fillable`
- add a constant for `VISIBILITY_REASON_IGNORED_SENDER`
- add a query scope like `scopeVisibleInStream(Builder $query): Builder`

Implementation sketch:

```php
public const VISIBILITY_REASON_IGNORED_SENDER = 'ignored_sender';

public function scopeVisibleInStream(Builder $query): Builder
{
    return $query->where(function (Builder $q): void {
        $q->where('source', '!=', 'email')
            ->orWhere(function (Builder $email): void {
                $email->where('source', 'email')
                    ->where('is_visible_in_stream', true);
            });
    });
}
```

- [ ] **Step 4: Apply the visible-in-stream scope to read-side query paths**

Update:
- `IdeaController@index()` recent thought query
- `IdeaController@stream()`
- `IdeaController@streamEmails()`
- `IdeaController@streamJira()` / `streamResearch()` / `streamPlans()` via the shared collection query path if those views can surface hidden email thoughts through shared behavior
- `ThoughtSearchService::search()` base query
- `RealtimeCheckController::realtimeCheck()`
- `ThoughtsApiController::recent()`
- `ThoughtsApiController::stats()`
- `McpController::browseRecent()`
- `McpController::thoughtStats()`

Do **not** apply the scope to `IdeaController@show()` or any other single-thought owner-authorized read-by-id flow.
As part of this step, explicitly audit authenticated API/MCP single-thought read-by-id endpoints, if any exist, and confirm they do **not** adopt the stream-visibility scope.

- [ ] **Step 5: Re-run the new visibility feature file and verify it passes**

Run:

```bash
php artisan test tests/Feature/EmailThoughtStreamVisibilityTest.php
```

Expected:
- PASS for recent feed, stream, email stream, search, realtime, and detail-page exception coverage

- [ ] **Step 6: Run the affected existing read-side feature files**

Run:

```bash
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/ThoughtTypePagesTest.php tests/Feature/SearchIncludeTagsTest.php tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php
```

Expected:
- PASS with no regressions to existing list, stream, type-page, or search behavior

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_03_23_000001_add_stream_visibility_to_thoughts_table.php app/Models/Thought.php app/Http/Controllers/IdeaController.php app/Http/Controllers/Api/RealtimeCheckController.php app/Http/Controllers/Api/ThoughtsApiController.php app/Http/Controllers/Api/McpController.php app/Services/ThoughtSearchService.php tests/Feature/EmailThoughtStreamVisibilityTest.php tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php
git commit -m "feat: filter hidden email thoughts from stream queries"
```

## Task 2: Add Sender Resolution And Reconciliation For Existing Thoughts

**Files:**
- Create: `app/Services/Email/ThoughtEmailSenderResolver.php`
- Create: `app/Jobs/ReconcileIgnoredSenderThoughtVisibility.php`
- Create: `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php`
- Modify: `app/Http/Controllers/EmailSenderRuleSettingsController.php`
- Modify: `app/Services/Email/EmailReviewActionService.php`
- Modify: `tests/Feature/EmailSenderRuleSettingsTest.php`
- Modify: `tests/Feature/EmailReviewInboxTest.php`
- Test: `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php`
- Test: `tests/Feature/EmailSenderRuleSettingsTest.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`

- [ ] **Step 1: Write the failing reconciliation and dispatch tests**

Add a new `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php` covering:
- job hides an `ImportedEmail`-backed thought for an ignored sender
- job hides a `CapturedInboundEmail`-backed thought for an ignored sender
- job restores a thought when the sender rule changes from `ignore` to another action
- job restores a thought when the sender rule is deleted
- job is idempotent when run twice
- job does not unhide a thought hidden for another reason
- job skips thoughts whose sender cannot be resolved safely
- job no-ops when `services.email_sender_policy.enabled` is `false`

Also extend:
- `tests/Feature/EmailSenderRuleSettingsTest.php` to assert create/update/delete dispatch reconciliation jobs
- `tests/Feature/EmailReviewInboxTest.php` to assert inbox `allow` / `ignore` / `extra_process` actions dispatch reconciliation jobs when sender classification changes, and do not dispatch visibility reconciliation when the sender-rule feature flag is off

Example job assertion:

```php
Bus::fake();

$this->actingAs($user)->patch(route('settings.email-sender-rules.update', $rule), [
    'action' => EmailSenderRule::ACTION_IGNORE,
]);

Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function ($job) use ($user) {
    return $job->userId === $user->id
        && $job->senderEmail === 'sender@example.com';
});
```

- [ ] **Step 2: Run the targeted reconciliation and sender-rule tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/EmailReviewInboxTest.php
```

Expected:
- FAIL because the resolver/job do not exist and rule mutations do not dispatch reconciliation yet

- [ ] **Step 3: Implement sender resolution for email thoughts**

Create `app/Services/Email/ThoughtEmailSenderResolver.php` with one responsibility: given a `Thought`, return the normalized sender email or `null`.

Resolution order must match the approved spec:
- `ImportedEmail`: `rule_email` -> formatted first participant from `from_json` -> approved metadata fallback
- `CapturedInboundEmail`: `rule_email` -> `sender_email` -> approved metadata fallback
- normalize through `EmailSenderRuleService::normalizeSender()`

Implementation sketch:

```php
final class ThoughtEmailSenderResolver
{
    public function resolve(Thought $thought): ?string
    {
        if ($thought->source !== 'email') {
            return null;
        }

        // Load ImportedEmail or CapturedInboundEmail for the thought.
        // Choose raw sender using the existing sender-rule precedence.
        // Return normalized sender or null.
    }
}
```

- [ ] **Step 4: Implement the idempotent reconciliation job**

Create `app/Jobs/ReconcileIgnoredSenderThoughtVisibility.php` so it:
- accepts `userId` and normalized `senderEmail`
- checks the current sender rule state for that user/sender
- iterates the current user's top-level email thoughts in batches, using `ThoughtEmailSenderResolver` to determine whether each thought matches the normalized sender
- hides matching thoughts with:
  - `is_visible_in_stream = false`
  - `visibility_reason = Thought::VISIBILITY_REASON_IGNORED_SENDER`
  - only if currently visible or already hidden for `ignored_sender`
- restores matching thoughts with:
  - `is_visible_in_stream = true`
  - `visibility_reason = null`
  - only where `visibility_reason = ignored_sender`

- [ ] **Step 5: Dispatch the reconciliation job from sender-rule write paths**

Update `EmailSenderRuleSettingsController`:
- after `store()`
- after `update()`
- after `destroy()`

Update `EmailReviewActionService::applySenderClassification()`:
- after the rule upsert and successful classification
- dispatch with the normalized sender email already validated inside the service

In both places:
- do not dispatch visibility reconciliation when `services.email_sender_policy.enabled` is `false`

Use the same job for both ignore and non-ignore transitions so the job can decide whether to hide or restore based on the sender’s current rule.

- [ ] **Step 6: Re-run the targeted tests and verify they pass**

Run:

```bash
php artisan test tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/EmailReviewInboxTest.php
```

Expected:
- PASS for reconciliation behavior and rule-change dispatch assertions

- [ ] **Step 7: Commit**

```bash
git add app/Services/Email/ThoughtEmailSenderResolver.php app/Jobs/ReconcileIgnoredSenderThoughtVisibility.php app/Http/Controllers/EmailSenderRuleSettingsController.php app/Services/Email/EmailReviewActionService.php tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/EmailReviewInboxTest.php
git commit -m "feat: reconcile email thought visibility from sender rules"
```

## Task 3: Apply Visibility Immediately When Email Thoughts Are Created

**Files:**
- Create: `app/Services/Email/EmailThoughtStreamVisibilityService.php`
- Modify: `app/Services/Email/EmailImportService.php`
- Modify: `app/Services/PostmarkInboundService.php`
- Modify: `app/Services/Email/EmailReviewActionService.php`
- Modify: `tests/Unit/Services/EmailImportServiceTest.php`
- Modify: `tests/Feature/PostmarkInboundWebhookTest.php`
- Modify: `tests/Feature/EmailReviewInboxTest.php`
- Test: `tests/Unit/Services/EmailImportServiceTest.php`
- Test: `tests/Feature/PostmarkInboundWebhookTest.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`

- [ ] **Step 1: Write the failing creation-time visibility tests**

Add targeted coverage for:
- imported-email thought created through `EmailImportService` remains visible for a non-ignored sender
- Postmark allow flow creates a visible thought for a non-ignored sender
- stale review inbox item can still create a thought, but if the sender is now ignored, the new thought is immediately hidden with `ignored_sender`
- feature-flag-disabled mode does not apply creation-time visibility changes

Example stale-review case:

```php
public function test_save_reviewed_email_as_thought_hides_new_thought_when_sender_is_now_ignored(): void
{
    config(['services.email_sender_policy.enabled' => true]);

    $user = User::factory()->create();
    ['imported' => $imported, 'inbox' => $inbox] = $this->createImportedEmailReviewFixture($user);

    EmailSenderRule::query()->updateOrCreate(
        ['user_id' => $user->id, 'sender_email' => 'newsletter@example.com'],
        ['action' => EmailSenderRule::ACTION_IGNORE],
    );

    $this->actingAs($user)->post(route('inbox.email-review.action', $inbox), [
        'action' => 'save_thought',
    ]);

    $thought = Thought::query()->whereKey($imported->fresh()->thought_id)->firstOrFail();
    $this->assertFalse($thought->is_visible_in_stream);
    $this->assertSame(Thought::VISIBILITY_REASON_IGNORED_SENDER, $thought->visibility_reason);
}
```

- [ ] **Step 2: Run the targeted email-creation tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/Services/EmailImportServiceTest.php tests/Feature/PostmarkInboundWebhookTest.php tests/Feature/EmailReviewInboxTest.php
```

Expected:
- FAIL because no service currently applies visibility state to newly created email thoughts

- [ ] **Step 3: Implement the shared email-thought visibility service**

Create `app/Services/Email/EmailThoughtStreamVisibilityService.php` with methods to:
- decide the current visibility state for a `(user, raw sender)` pair
- apply that state to a created `Thought`

Implementation sketch:

```php
final class EmailThoughtStreamVisibilityService
{
    public function applyToThought(Thought $thought, User $user, string $rawSender): void
    {
        $decision = $this->senderRuleService->resolveForUser($user, $rawSender);

        $thought->update([
            'is_visible_in_stream' => $decision['action'] !== EmailSenderRule::ACTION_IGNORE,
            'visibility_reason' => $decision['action'] === EmailSenderRule::ACTION_IGNORE
                ? Thought::VISIBILITY_REASON_IGNORED_SENDER
                : null,
        ]);
    }
}
```

- [ ] **Step 4: Wire the visibility service into email thought creation flows**

Update:
- `EmailImportService` after a thought is created and before the row is finalized
- `PostmarkInboundService` after allow/extra-process thought creation
- `EmailReviewActionService::saveReviewedEmailAsThought()` after the thought is created or recovered and before the transaction finishes

Keep current `ignore` short-circuit behavior in import/webhook flows unchanged: ignored inbound email still stores no thought there today. The new visibility application is mainly for centralization and stale/replayed creation paths that still produce a thought after sender state has changed.

Guard the creation-time visibility application behind `services.email_sender_policy.enabled` so the feature flag behavior matches the spec.

- [ ] **Step 5: Re-run the targeted email-creation tests and verify they pass**

Run:

```bash
php artisan test tests/Unit/Services/EmailImportServiceTest.php tests/Feature/PostmarkInboundWebhookTest.php tests/Feature/EmailReviewInboxTest.php
```

Expected:
- PASS with newly created email thoughts carrying the correct stream visibility state

- [ ] **Step 6: Commit**

```bash
git add app/Services/Email/EmailThoughtStreamVisibilityService.php app/Services/Email/EmailImportService.php app/Services/PostmarkInboundService.php app/Services/Email/EmailReviewActionService.php tests/Unit/Services/EmailImportServiceTest.php tests/Feature/PostmarkInboundWebhookTest.php tests/Feature/EmailReviewInboxTest.php
git commit -m "feat: apply stream visibility to new email thoughts"
```

## Task 4: Add Rollout Backfill And Final Verification

**Files:**
- Create: `app/Console/Commands/BackfillIgnoredSenderThoughtVisibilityCommand.php`
- Create: `tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php`
- Modify: `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php`
- Test: `tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php`
- Test: `tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php`
- Test: `tests/Feature/EmailThoughtStreamVisibilityTest.php`
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`
- Test: `tests/Feature/ThoughtTypePagesTest.php`
- Test: `tests/Feature/SearchIncludeTagsTest.php`
- Test: `tests/Feature/ThoughtsApiTest.php`
- Test: `tests/Feature/McpApiTest.php`
- Test: `tests/Feature/EmailSenderRuleSettingsTest.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`
- Test: `tests/Unit/Services/EmailImportServiceTest.php`
- Test: `tests/Feature/PostmarkInboundWebhookTest.php`

- [ ] **Step 1: Write the failing rollout-backfill tests**

Create `tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php` covering:
- command dispatches one reconciliation job per existing ignored sender rule
- command skips non-ignored rules
- command is safe when no ignored rules exist
- command no-ops when `services.email_sender_policy.enabled` is `false`

Example shape:

```php
public function test_backfill_command_dispatches_jobs_for_existing_ignored_rules(): void
{
    Bus::fake();

    $user = User::factory()->create();
    EmailSenderRule::query()->create([
        'user_id' => $user->id,
        'sender_email' => 'ignored@example.com',
        'action' => EmailSenderRule::ACTION_IGNORE,
    ]);

    $this->artisan('email:backfill-ignored-sender-thought-visibility')
        ->assertSuccessful();

    Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class);
}
```

- [ ] **Step 2: Run the rollout-backfill tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php
```

Expected:
- FAIL because the command does not exist yet

- [ ] **Step 3: Implement the rollout command**

Create `app/Console/Commands/BackfillIgnoredSenderThoughtVisibilityCommand.php` to:
- query all `EmailSenderRule` rows where `action = ignore`
- dispatch `ReconcileIgnoredSenderThoughtVisibility` for each distinct `(user_id, sender_email)`
- print a short summary of how many jobs were queued
- return early with a short message when `services.email_sender_policy.enabled` is `false`

The tests together should prove both halves of the rollout behavior:
- `BackfillIgnoredSenderThoughtVisibilityCommandTest` proves the command fans out the correct jobs
- `ReconcileIgnoredSenderThoughtVisibilityTest` proves a job run actually hides existing thoughts for an already-ignored sender

Suggested signature:

```php
protected $signature = 'email:backfill-ignored-sender-thought-visibility';
```

- [ ] **Step 4: Re-run the rollout tests and then the full affected suite**

Run:

```bash
php artisan test tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php tests/Feature/ReconcileIgnoredSenderThoughtVisibilityTest.php tests/Feature/EmailThoughtStreamVisibilityTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/ThoughtTypePagesTest.php tests/Feature/SearchIncludeTagsTest.php tests/Feature/ThoughtsApiTest.php tests/Feature/McpApiTest.php tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/EmailReviewInboxTest.php tests/Unit/Services/EmailImportServiceTest.php tests/Feature/PostmarkInboundWebhookTest.php
```

Expected:
- PASS for rollout backfill, reconciliation, stream filtering, sender-rule flows, inbox review flows, import, and Postmark handling

- [ ] **Step 5: Run formatting/diagnostics**

Run:

```bash
./vendor/bin/pint --dirty
```

Expected:
- PASS with any touched PHP files formatted

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/BackfillIgnoredSenderThoughtVisibilityCommand.php tests/Feature/BackfillIgnoredSenderThoughtVisibilityCommandTest.php
git commit -m "feat: backfill ignored sender thought visibility"
```

## Notes For Execution

- Follow `@superpowers/test-driven-development` strictly: write each failing test first, run it, then add the minimal code to pass.
- Keep direct thought detail reads out of the stream-visibility scope. Hidden email thoughts must still be accessible by `thoughts.show`.
- Treat `ignored_sender` as a shared constant in code; do not scatter string literals across jobs, controllers, and tests.
- Preserve current import/webhook ignore behavior: when sender-policy ignore already short-circuits capture, keep that behavior unless a test proves a user-facing regression. The new visibility work is for existing thoughts plus any stale/replayed flow that still creates a thought.
- Make the reconciliation job conservative: skip unresolvable senders, never overwrite another future visibility reason, and only restore rows hidden for `ignored_sender`.
- Prefer one shared `Thought` visibility scope over copying `where('is_visible_in_stream', true)` into every controller.
- Before coding Task 1, audit other direct `Thought::query()`, `Thought::`, and `thoughts` table listing/count surfaces with `rg "Thought::query|Thought::|thoughts"` in `app/Http/Controllers` and `app/Services` so no browse/count endpoint is missed.
- Task 1 and Task 3 should ship together in production, or Task 3 should ship first. Task 1 hides rows correctly, but Task 3 is what prevents newly created stale-flow email thoughts from leaking in as visible before reconciliation.

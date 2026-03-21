# Email Sender Rules And Research Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-user exact sender-email rules across Postmark inbound and Fastmail sync so emails can be allowed, ignored, routed to Inbox review, or extra-processed into linked IdeaTub research.

**Architecture:** Keep the existing split between Postmark inbound capture and Fastmail mailbox sync, but unify their decision logic through a shared sender-rule service. Preserve `imported_emails` for Fastmail, add a dedicated durable matched-Postmark email record for inbound capture, route unknown senders into Inbox review items through one shared review service, and queue newsletter research asynchronously so original email storage and thought creation remain reliable even when enrichment partially fails.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, Eloquent, queued jobs, scheduled/background processing, Pest/Laravel test runner while matching surrounding repo test-file conventions, `ThoughtCaptureService`, Inbox actions, Postmark inbound webhook flow, Fastmail sync jobs.

**Spec:** `docs/superpowers/specs/2026-03-21-email-sender-rules-and-research-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `dev/2026-03-21-youtube-transcript-validation.md` | Record the chosen YouTube transcript retrieval approach and failure boundaries before wiring the newsletter job to a concrete provider. |
| `config/services.php` | Add the sender-policy rollout flag and any related per-environment toggles. |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_email_sender_rules_table.php` | Create the per-user exact-sender rules table. |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_captured_inbound_emails_table.php` | Create the durable matched-Postmark email record table. |
| `database/migrations/YYYY_MM_DD_HHMMSS_add_sender_rule_fields_to_imported_emails_table.php` | Add rule decision and linkage fields to Fastmail email records. |
| `database/migrations/YYYY_MM_DD_HHMMSS_add_processing_fields_to_captured_inbound_emails_table.php` | Add linkage/status fields if not included in the initial Postmark durable-record migration. |
| `app/Models/EmailSenderRule.php` | Sender-rule model with normalized exact email and action constants/helpers. |
| `app/Models/CapturedInboundEmail.php` | Durable matched-Postmark email record with thought/research/inbox linkage. |
| `app/Models/User.php` | Add `emailSenderRules()` and `capturedInboundEmails()` relationships. |
| `app/Http/Controllers/EmailSenderRuleSettingsController.php` | Settings page to create/update/delete sender rules. |
| `resources/views/settings/email-sender-rules.blade.php` | Blade UI for per-user sender rules. |
| `resources/views/layouts/idea.blade.php` | Add sender-rules settings link. |
| `routes/web.php` | Register sender-rules settings routes and review action routes. |
| `app/Services/Email/EmailSenderRuleService.php` | Shared sender-rule resolution for Postmark and Fastmail. |
| `app/Services/Email/EmailReviewInboxService.php` | Create/update Inbox review items for unknown/reviewed senders. |
| `app/Services/Email/EmailReviewActionService.php` | Apply review actions like allow/ignore/extra-process and save a reviewed email as a thought. |
| `app/Services/Email/EmailLinkExtractor.php` | Extract and normalize links from email body text. |
| `app/Services/Email/YouTubeTranscriptService.php` | Fetch transcript text for supported YouTube links using the validated strategy from the dev note. |
| `app/Services/Email/EmailNewsletterResearchService.php` | Build best-effort newsletter inputs and save linked research documents for `extra_process` emails. |
| `app/Jobs/ProcessExtraEmailResearch.php` | Background job for newsletter analysis and research creation. |
| `app/Services/PostmarkInboundService.php` | Persist matched Postmark emails, resolve sender actions, create review items, create thoughts, and dispatch research jobs. |
| `app/Services/Email/EmailImportService.php` | Resolve sender actions for Fastmail imports, store decision metadata, create review items or thoughts, and dispatch research jobs. |
| `app/Services/Email/EmailFilterService.php` | Continue supporting hard parsing failures, but stop deciding product behavior once sender-policy-first mode is enabled. |
| `app/Http/Controllers/InboxController.php` | Add review-item actions if review triage is exposed via Inbox item forms. |
| `app/Services/Inbox/InboxActionService.php` | Reuse or extend Inbox actions if reviewed emails can be saved as thoughts from Inbox. |
| `resources/views/inbox/index.blade.php` | Render review-specific action buttons for email review items. |
| `resources/views/idea/index_thought_cards.blade.php` | Show lightweight email research status for recent/email thoughts. |
| `resources/views/idea/stream_thoughts.blade.php` | Show lightweight email research status in Stream. |
| `tests/Feature/EmailSenderRuleSettingsTest.php` | Settings page and CRUD coverage for sender rules. |
| `tests/Unit/Services/EmailSenderRuleServiceTest.php` | Exact-match rule resolution, normalization, and unknown fallback tests. |
| `tests/Feature/PostmarkInboundWebhookTest.php` | Postmark end-to-end behavior for ignore/review/allow/extra-process. |
| `tests/Unit/Services/EmailImportServiceTest.php` | Fastmail import behavior for sender actions, review routing, and linkage metadata. |
| `tests/Feature/BackfillMailAccountJobTest.php` | Fastmail job-level behavior with sender rules and research dispatch. |
| `tests/Feature/InboxActionsTest.php` | Review action coverage if Inbox becomes the first review UI. |
| `tests/Feature/EmailReviewInboxTest.php` | Review item creation and sender classification/save-as-thought actions if split from generic inbox tests. |
| `tests/Unit/Services/EmailLinkExtractorTest.php` | Link extraction behavior from newsletter body text. |
| `tests/Unit/Services/YouTubeTranscriptServiceTest.php` | Transcript fetch success/failure behavior. |
| `tests/Unit/Services/EmailNewsletterResearchServiceTest.php` | Best-effort research creation, skip rules, and linkage metadata tests. |
| `tests/Feature/ProcessExtraEmailResearchJobTest.php` | Background newsletter research job end-to-end behavior. |
| `tests/Feature/EmailThoughtStatusDisplayTest.php` | Verify email thought cards expose lightweight queued/completed/partial/skipped/failed research status. |

### Architectural decision note

This plan intentionally keeps a dual durable-email model in v1:

- `imported_emails` for Fastmail
- `captured_inbound_emails` for matched Postmark mail

This is a deliberate tradeoff to avoid invasive changes to the existing Fastmail sync schema and unique-key assumptions. The cost is duplicated linkage fields and a research job that must accept two stored-email record types. The acceptance criteria for this choice are:

- both storage models expose the same user-visible guarantees from the spec
- both can link to Inbox review items, email thoughts, and research thoughts
- both can record processing status and partial-failure metadata

### Rollout decision note

V1 should ship behind a sender-policy feature flag so the app can preserve current behavior when the new policy layer is disabled. The intended steady-state behavior is still sender-policy-first with unknown sender => `review`, but the plan below requires explicit implementation of both:

- flag off => existing email behavior remains intact
- flag on => new sender-policy-first behavior is enforced

### Visibility decision note

V1 processing visibility is metadata-first, not dashboard-first. The implementation should persist queued/completed/partial/skipped/failed states on stored email records and linked thoughts, but does not need a dedicated research-status page in this slice.

---

## Chunk 1: Preflight and sender-rule settings

### Task 1.1: Validate the YouTube transcript retrieval approach before coding

**Files:**
- Create: `dev/2026-03-21-youtube-transcript-validation.md`
- Reference: `docs/superpowers/specs/2026-03-21-email-sender-rules-and-research-design.md`

- [ ] **Step 1: Create the validation note with the required headings**

Create `dev/2026-03-21-youtube-transcript-validation.md` with headings for:

- chosen transcript source/library
- supported YouTube URL shapes
- rate limits and failure modes
- data returned to the app
- fallback behavior when transcripts are unavailable
- implementation constraints for tests and production

- [ ] **Step 2: Manually validate one successful and one failing transcript fetch**

Run a one-off local spike using the preferred package or HTTP approach.

Expected:

- one known video returns transcript text
- one unavailable/blocked video demonstrates the failure path you will treat as best-effort

- [ ] **Step 3: Record the exact v1 decision**

In the dev note, write:

- the concrete transcript-fetching mechanism
- the exact error categories the app should treat as recoverable
- the exact output shape `YouTubeTranscriptService` should return

- [ ] **Step 4: Commit**

```bash
git add dev/2026-03-21-youtube-transcript-validation.md
git commit -m "Document YouTube transcript integration"
```

---

### Task 1.2: Add sender-rule schema and model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_email_sender_rules_table.php`
- Create: `app/Models/EmailSenderRule.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/EmailSenderRuleSettingsTest.php`

- [ ] **Step 1: Write the failing sender-rule settings test**

Create `tests/Feature/EmailSenderRuleSettingsTest.php` with:

```php
public function test_authenticated_user_can_store_sender_rule(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
        'sender_email' => 'natesnewsletter@substack.com',
        'action' => 'extra_process',
    ]);

    $response->assertRedirect(route('settings.email-sender-rules.index'));
    $this->assertDatabaseHas('email_sender_rules', [
        'user_id' => $user->id,
        'sender_email' => 'natesnewsletter@substack.com',
        'action' => 'extra_process',
    ]);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php --filter test_authenticated_user_can_store_sender_rule`

Expected: FAIL because the route/table/model do not exist yet.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_email_sender_rules_table`

Implement:

```php
Schema::create('email_sender_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('sender_email', 255);
    $table->string('action', 32);
    $table->timestamps();

    $table->unique(['user_id', 'sender_email']);
    $table->index(['user_id', 'action']);
});
```

- [ ] **Step 4: Create the model**

Create `app/Models/EmailSenderRule.php` with:

- `fillable` for `user_id`, `sender_email`, `action`
- normalized action constants:
  - `allow`
  - `ignore`
  - `review`
  - `extra_process`
- a `saving` hook or mutator that lowercases and trims `sender_email`
- `user()` relationship

- [ ] **Step 5: Add the user relationship**

In `app/Models/User.php`, add:

```php
public function emailSenderRules()
{
    return $this->hasMany(EmailSenderRule::class);
}
```

- [ ] **Step 6: Run migrations and the focused test**

Run:

- `php artisan migrate`
- `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php --filter test_authenticated_user_can_store_sender_rule`

Expected: FAIL because the controller/routes/view still do not exist.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*_create_email_sender_rules_table.php app/Models/EmailSenderRule.php app/Models/User.php tests/Feature/EmailSenderRuleSettingsTest.php
git commit -m "feat: add email sender rule schema"
```

---

### Task 1.3: Add sender-rule settings routes, controller, and view

**Files:**
- Create: `app/Http/Controllers/EmailSenderRuleSettingsController.php`
- Create: `resources/views/settings/email-sender-rules.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Test: `tests/Feature/EmailSenderRuleSettingsTest.php`

- [ ] **Step 1: Extend the feature test for page rendering and delete/update**

Add tests for:

- page requires auth
- authenticated user sees the settings page
- duplicate sender rule for same user is rejected
- user can delete their rule
- user cannot delete another user's rule

- [ ] **Step 2: Run the feature test file**

Run: `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php`

Expected: FAIL because the routes/controller/view are missing.

- [ ] **Step 3: Add the routes**

In `routes/web.php` inside the auth group, add:

```php
Route::get('/settings/email-sender-rules', [EmailSenderRuleSettingsController::class, 'index'])->name('settings.email-sender-rules.index');
Route::post('/settings/email-sender-rules', [EmailSenderRuleSettingsController::class, 'store'])->name('settings.email-sender-rules.store');
Route::patch('/settings/email-sender-rules/{emailSenderRule}', [EmailSenderRuleSettingsController::class, 'update'])->name('settings.email-sender-rules.update');
Route::delete('/settings/email-sender-rules/{emailSenderRule}', [EmailSenderRuleSettingsController::class, 'destroy'])->name('settings.email-sender-rules.destroy');
```

- [ ] **Step 4: Add the controller**

Create `EmailSenderRuleSettingsController` with methods:

- `index(Request $request): View`
- `store(Request $request): RedirectResponse`
- `update(Request $request, EmailSenderRule $emailSenderRule): RedirectResponse`
- `destroy(Request $request, EmailSenderRule $emailSenderRule): RedirectResponse`

Use owner-only checks matching the current settings-controller style.

- [ ] **Step 5: Build the Blade view**

Follow the styling pattern from:

- `resources/views/settings/inbound-emails.blade.php`
- `resources/views/settings/email-accounts.blade.php`

Render:

- intro text explaining exact sender rules
- rows for existing rules
- add form with sender email and action select
- per-row update/delete actions

- [ ] **Step 6: Add the nav link**

In `resources/views/layouts/idea.blade.php`, add a settings link for `Email Sender Rules` near the existing email settings links.

- [ ] **Step 7: Re-run the feature tests**

Run: `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/EmailSenderRuleSettingsController.php resources/views/settings/email-sender-rules.blade.php resources/views/layouts/idea.blade.php routes/web.php tests/Feature/EmailSenderRuleSettingsTest.php
git commit -m "feat: add email sender rule settings"
```

---

### Task 1.4: Add the sender-policy rollout flag

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `tests/Feature/EmailSenderRuleSettingsTest.php`
- Modify: `tests/Feature/PostmarkInboundWebhookTest.php`
- Modify: `tests/Feature/BackfillMailAccountJobTest.php`

- [ ] **Step 1: Add the failing feature-flag tests**

Add assertions that:

- the sender-rule settings page returns `404` when the flag is off
- Postmark inbound preserves current behavior when the flag is off
- Fastmail import preserves current behavior when the flag is off

- [ ] **Step 2: Run the focused tests**

Run:

- `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php`
- `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`
- `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: FAIL because the flag does not exist yet.

- [ ] **Step 3: Add the config entry**

In `config/services.php`, add a feature flag such as:

```php
'email_sender_policy' => [
    'enabled' => filter_var(env('EMAIL_SENDER_POLICY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
],
```

Add to `.env.example`:

```bash
EMAIL_SENDER_POLICY_ENABLED=false
```

- [ ] **Step 4: Wire the current tests to the default-off behavior**

Update the tests so they set:

```php
config(['services.email_sender_policy.enabled' => true]);
```

only when they are asserting the new sender-policy behavior.

- [ ] **Step 5: Re-run the focused tests**

Run:

- `php artisan test tests/Feature/EmailSenderRuleSettingsTest.php`
- `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`
- `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: PASS for the flag-off defaults and controlled opt-in cases.

- [ ] **Step 6: Commit**

```bash
git add config/services.php .env.example tests/Feature/EmailSenderRuleSettingsTest.php tests/Feature/PostmarkInboundWebhookTest.php tests/Feature/BackfillMailAccountJobTest.php
git commit -m "chore: add email sender policy feature flag"
```

---

## Chunk 2: Shared sender-rule resolution and durable email records

### Task 2.1: Implement the shared sender-rule evaluator

**Files:**
- Create: `app/Services/Email/EmailSenderRuleService.php`
- Test: `tests/Unit/Services/EmailSenderRuleServiceTest.php`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/Services/EmailSenderRuleServiceTest.php` covering:

- exact sender match returns explicit action
- unknown sender defaults to `review`
- mixed-case input is normalized
- plus-addressing is not stripped
- if multiple sender mailboxes are present unexpectedly, the first parsed sender wins and the raw sender value is preserved for debugging metadata

Example:

```php
public function test_unknown_sender_defaults_to_review(): void
{
    $user = User::factory()->create();

    $decision = app(EmailSenderRuleService::class)->resolveForUser($user, 'Unknown@Example.com');

    $this->assertSame('review', $decision['action']);
    $this->assertSame('unknown@example.com', $decision['normalized_sender']);
}
```

- [ ] **Step 2: Run the unit tests**

Run: `php artisan test tests/Unit/Services/EmailSenderRuleServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement the service**

Create a service method such as:

```php
public function resolveForUser(User $user, string $rawSender): array
```

Return a normalized payload like:

```php
[
    'action' => 'review',
    'normalized_sender' => 'unknown@example.com',
    'rule_id' => null,
]
```

or

```php
[
    'action' => 'extra_process',
    'normalized_sender' => 'natesnewsletter@substack.com',
    'rule_id' => 123,
    'raw_sender' => 'Nate <natesnewsletter@substack.com>',
]
```

- [ ] **Step 4: Re-run the unit tests**

Run: `php artisan test tests/Unit/Services/EmailSenderRuleServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Email/EmailSenderRuleService.php tests/Unit/Services/EmailSenderRuleServiceTest.php
git commit -m "feat: add shared email sender rule resolver"
```

---

### Task 2.2: Add the durable matched-Postmark email table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_captured_inbound_emails_table.php`
- Create: `app/Models/CapturedInboundEmail.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/PostmarkInboundWebhookTest.php`

- [ ] **Step 1: Add a failing Postmark assertion for stored matched emails**

Extend `tests/Feature/PostmarkInboundWebhookTest.php` with:

```php
public function test_matched_postmark_email_is_stored_before_thought_creation(): void
{
    User::factory()->create(['email' => 'sender@example.com']);
    $this->fakeOpenRouterForInbound();

    $this->postJson($this->webhookUrl(), $this->minimalPayload([
        'TextBody' => 'Hello',
        'MessageID' => 'msg-store-1',
    ]))->assertStatus(200);

    $this->assertDatabaseHas('captured_inbound_emails', [
        'message_id' => 'msg-store-1',
        'sender_email' => 'sender@example.com',
    ]);
}
```

- [ ] **Step 2: Run the Postmark test**

Run: `php artisan test tests/Feature/PostmarkInboundWebhookTest.php --filter test_matched_postmark_email_is_stored_before_thought_creation`

Expected: FAIL because the table/model do not exist.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_captured_inbound_emails_table`

Implement fields for:

- `user_id`
- `message_id` unique
- `sender_email`
- `subject`
- `body_text`
- `received_at`
- `rule_action`
- `rule_email`
- `thought_id` nullable
- `research_thought_id` nullable
- `review_inbox_item_id` nullable
- `processing_status`
- `processing_metadata_json` nullable
- timestamps

- [ ] **Step 4: Create the model**

Create `CapturedInboundEmail` with:

- `fillable`
- casts for timestamps and JSON
- `user()` and `thought()` relationships

- [ ] **Step 5: Add the user relationship**

In `app/Models/User.php`, add:

```php
public function capturedInboundEmails()
{
    return $this->hasMany(CapturedInboundEmail::class);
}
```

- [ ] **Step 6: Re-run the focused Postmark test**

Run: `php artisan test tests/Feature/PostmarkInboundWebhookTest.php --filter test_matched_postmark_email_is_stored_before_thought_creation`

Expected: still FAIL because the service does not persist to the table yet.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*_create_captured_inbound_emails_table.php app/Models/CapturedInboundEmail.php app/Models/User.php tests/Feature/PostmarkInboundWebhookTest.php
git commit -m "feat: add captured inbound email persistence"
```

---

### Task 2.3: Extend Fastmail email records for sender-rule and linkage metadata

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_sender_rule_fields_to_imported_emails_table.php`
- Modify: `app/Models/ImportedEmail.php`
- Test: `tests/Unit/Services/EmailImportServiceTest.php`

- [ ] **Step 1: Add a failing import-service assertion for rule metadata**

Extend `tests/Unit/Services/EmailImportServiceTest.php` so imported rows can assert:

- `rule_action`
- `rule_email`
- `review_inbox_item_id`
- `research_thought_id`
- `processing_metadata_json`

- [ ] **Step 2: Run the unit test file**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: FAIL because the columns/model casts do not exist.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration add_sender_rule_fields_to_imported_emails_table`

Add columns:

- `rule_action` nullable string
- `rule_email` nullable string
- `review_inbox_item_id` nullable foreign id to `inbox_items`
- `research_thought_id` nullable foreign UUID to `thoughts`
- `processing_metadata_json` nullable json

Also update the meaning of the existing `processing_status` field so Fastmail can mirror the same research-status lifecycle as Postmark, for example:

- `review_queued`
- `imported`
- `research_queued`
- `research_completed`
- `research_partial`
- `research_skipped`
- `research_failed`

- [ ] **Step 4: Update the model**

Add fields to `fillable` and casts in `app/Models/ImportedEmail.php`.

- [ ] **Step 5: Re-run the import-service test**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: still FAIL because the service logic is not updated yet.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/*_add_sender_rule_fields_to_imported_emails_table.php app/Models/ImportedEmail.php tests/Unit/Services/EmailImportServiceTest.php
git commit -m "feat: add sender rule linkage fields to imported emails"
```

---

## Chunk 3: Ingestion behavior for ignore, review, allow, and extra-process

### Task 3.1: Add Fastmail sender-policy behavior to `EmailImportService`

**Files:**
- Modify: `app/Services/Email/EmailImportService.php`
- Modify: `app/Services/Email/EmailFilterService.php`
- Create: `app/Services/Email/EmailReviewInboxService.php`
- Test: `tests/Unit/Services/EmailImportServiceTest.php`
- Test: `tests/Feature/BackfillMailAccountJobTest.php`

- [ ] **Step 1: Expand the failing import-service tests**

Add explicit tests for:

- `ignore` stores nothing new for the message
- `review` stores the email row and creates no thought
- explicit `review` sender rule behaves the same as unknown-sender review routing
- `allow` stores the email row and creates a thought even if old heuristics would have filtered it
- `extra_process` stores the row, creates the thought, and dispatches the research job
- duplicate `(mail_account_id, provider_message_id)` replay does not create a second stored row or second thought under sender-policy mode
- unknown sender defaults to `review`

- [ ] **Step 2: Run the focused unit tests**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: FAIL because sender-rule-first logic is not implemented.

- [ ] **Step 3: Implement `EmailReviewInboxService`**

Create a focused service that:

- accepts a stored email record reference and user
- creates or reuses an Inbox item with `generator_type = email_sender_review`
- writes a stable `source_data` shape so review actions can recover the stored email record and sender email:

```php
[
    'stored_email_type' => 'imported_email',
    'stored_email_id' => 123,
    'sender_email' => 'unknown@example.com',
    'rule_action' => 'review',
]
```

- [ ] **Step 4: Update `EmailImportService`**

Flow:

1. normalize sender and resolve sender action via `EmailSenderRuleService`
2. if `ignore`, return without creating a durable row
3. otherwise persist/update the `ImportedEmail` row with rule metadata
4. for `review`, persist cheap extracted links into `processing_metadata_json`, create/reuse the Inbox item, and stop
5. for `allow`, create the normal email thought with `source_metadata` including the stored email record id and sender rule action
6. for `extra_process`, create the normal email thought with the same linkage metadata and dispatch `ProcessExtraEmailResearch`

- [ ] **Step 5: Reduce `EmailFilterService` to hard parsing/helper logic only**

Do not let bulk heuristics override explicit `allow`. If needed, keep hard parse failures separate from product-behavior filtering.

- [ ] **Step 6: Re-run the import-service unit tests**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: PASS.

- [ ] **Step 7: Add job-level Fastmail coverage**

Update `tests/Feature/BackfillMailAccountJobTest.php` to assert:

- unknown sender creates a review inbox item
- explicit allow creates a thought even for newsletter-like sender
- extra_process dispatches the research job once
- duplicate provider-message replay remains idempotent with sender-policy mode enabled

- [ ] **Step 8: Run the Fastmail job tests**

Run: `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Email/EmailImportService.php app/Services/Email/EmailFilterService.php app/Services/Email/EmailReviewInboxService.php tests/Unit/Services/EmailImportServiceTest.php tests/Feature/BackfillMailAccountJobTest.php
git commit -m "feat: apply sender rules to Fastmail imports"
```

---

### Task 3.2: Add Postmark sender-policy behavior to `PostmarkInboundService`

**Files:**
- Modify: `app/Services/PostmarkInboundService.php`
- Modify: `app/Services/Email/EmailReviewInboxService.php`
- Modify: `tests/Feature/PostmarkInboundWebhookTest.php`

- [ ] **Step 1: Expand the failing Postmark tests**

Add tests for:

- ignored sender returns 200 and stores nothing
- unknown sender creates a captured inbound email plus review Inbox item
- explicit `review` sender rule creates a captured inbound email plus review Inbox item
- allowed sender creates captured inbound email plus thought
- extra_process sender creates captured inbound email plus thought and dispatches the research job

- [ ] **Step 2: Run the Postmark test file**

Run: `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`

Expected: FAIL because Postmark still uses matched/unmatched thought-only behavior.

- [ ] **Step 3: Refactor `PostmarkInboundService` to persist first**

Update the flow so that:

- sender rules are resolved after user resolution
- `EmailReviewInboxService` is reused so Postmark and Fastmail create the same review-item shape and `source_data`
- `ignore` exits before durable storage
- `review` stores `CapturedInboundEmail`, persists cheap extracted links into `processing_metadata_json`, creates/reuses Inbox item, and returns `200`
- `allow` stores `CapturedInboundEmail`, creates a thought with `source_metadata` including the stored email record id and sender rule action, links it, and returns `200`
- `extra_process` stores `CapturedInboundEmail`, creates a thought with the same linkage metadata, dispatches `ProcessExtraEmailResearch`, and returns `200`

- [ ] **Step 4: Preserve current webhook guarantees**

Keep:

- wrong token => `404`
- empty body => `200`
- duplicate `MessageID` => idempotent `200`
- malformed user resolution behavior safe for retries

- [ ] **Step 5: Re-run the Postmark tests**

Run: `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PostmarkInboundService.php app/Services/Email/EmailReviewInboxService.php tests/Feature/PostmarkInboundWebhookTest.php
git commit -m "feat: apply sender rules to Postmark inbound"
```

---

## Chunk 4: Inbox review actions

### Task 4.1: Create review-item actions to classify a sender

**Files:**
- Create: `app/Services/Email/EmailReviewActionService.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/inbox/index.blade.php`
- Test: `tests/Feature/EmailReviewInboxTest.php`

- [ ] **Step 1: Write the failing review-action tests**

Create `tests/Feature/EmailReviewInboxTest.php` covering:

- review Inbox item shows only to the owner
- review Inbox item renders allow/ignore/extra-process/save-thought controls
- user can mark the sender as `allow`
- user can mark the sender as `ignore`
- user can mark the sender as `extra_process`
- these actions create/update `email_sender_rules`

- [ ] **Step 2: Run the feature tests**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php`

Expected: FAIL because the review actions do not exist.

- [ ] **Step 3: Add review routes**

In `routes/web.php`, add Inbox review action endpoints such as:

```php
Route::post('/inbox/{inboxItem}/email-review/action', [InboxController::class, 'applyEmailReviewAction'])->name('inbox.email-review.action');
```

- [ ] **Step 4: Implement `EmailReviewActionService`**

Service responsibilities:

- validate that the Inbox item is an email-review item
- create/update the matching `EmailSenderRule`
- apply sender actions to future mail only; do not retro-process the already-stored review email
- mark the review Inbox item done
- update the stored email record with the chosen action if needed

- [ ] **Step 5: Implement the controller action**

Add `applyEmailReviewAction()` to `InboxController` with owner authorization and validation:

```php
'action' => 'required|in:allow,ignore,extra_process'
```

- [ ] **Step 6: Render the review buttons in the Inbox view**

Update `resources/views/inbox/index.blade.php` so `generator_type = email_sender_review` items show action forms for:

- `allow`
- `ignore`
- `extra_process`
- `save_thought`

Keep the existing generic Inbox actions intact for non-review items.

- [ ] **Step 7: Re-run the review tests**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Email/EmailReviewActionService.php app/Http/Controllers/InboxController.php routes/web.php resources/views/inbox/index.blade.php tests/Feature/EmailReviewInboxTest.php
git commit -m "feat: add email review sender actions"
```

---

### Task 4.2: Add “save reviewed email as thought” behavior

**Files:**
- Modify: `app/Services/Email/EmailReviewActionService.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `tests/Feature/EmailReviewInboxTest.php`

- [ ] **Step 1: Add the failing save-as-thought review test**

Append a test like:

```php
public function test_user_can_save_reviewed_email_as_thought_without_waiting_for_reimport(): void
{
    // create review inbox item + stored email record
    // POST review save-thought action
    // assert thought exists and is linked back to the stored email
}
```

- [ ] **Step 2: Run the focused test**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php --filter save_reviewed_email_as_thought`

Expected: FAIL because the action is not implemented.

- [ ] **Step 3: Implement the service method**

Add a method that:

- resolves the stored email record from Inbox `source_data`
- creates the email thought via `ThoughtCaptureService`
- links the thought back to the stored email record
- marks the Inbox review item done

- [ ] **Step 4: Add the controller branch**

Support a second validated action such as:

```php
'action' => 'required|in:allow,ignore,extra_process,save_thought'
```

- [ ] **Step 5: Re-run the review test file**

Run: `php artisan test tests/Feature/EmailReviewInboxTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Email/EmailReviewActionService.php app/Http/Controllers/InboxController.php tests/Feature/EmailReviewInboxTest.php
git commit -m "feat: save reviewed emails as thoughts"
```

---

## Chunk 5: Newsletter research pipeline

### Task 5.1: Add link extraction and transcript services

**Files:**
- Create: `app/Services/Email/EmailLinkExtractor.php`
- Create: `app/Services/Email/YouTubeTranscriptService.php`
- Test: `tests/Unit/Services/EmailLinkExtractorTest.php`
- Test: `tests/Unit/Services/YouTubeTranscriptServiceTest.php`

- [ ] **Step 1: Write the failing link extractor tests**

Create tests for:

- extracting URLs from plain email text
- preserving multiple links
- recognizing YouTube URLs
- matching the same extracted-links shape already persisted on review-path stored email rows

- [ ] **Step 2: Write the failing transcript-service tests**

Use the validated dev note to test:

- successful transcript fetch
- unavailable transcript returns a recoverable failure
- unsupported YouTube URL shape returns a recoverable failure

- [ ] **Step 3: Run the unit tests**

Run:

- `php artisan test tests/Unit/Services/EmailLinkExtractorTest.php`
- `php artisan test tests/Unit/Services/YouTubeTranscriptServiceTest.php`

Expected: FAIL because the services do not exist.

- [ ] **Step 4: Implement `EmailLinkExtractor`**

Return a normalized structure like:

```php
[
    ['url' => 'https://youtube.com/watch?v=abc', 'type' => 'youtube'],
    ['url' => 'https://example.com/post', 'type' => 'generic'],
]
```

If Chunk 3 used a temporary inline extractor for cheap review-path link persistence, replace it now with this shared service so review and extra-process paths store the same link format.

- [ ] **Step 5: Implement `YouTubeTranscriptService`**

Follow the dev note exactly and return a best-effort result shape such as:

```php
[
    'ok' => true,
    'transcript' => '...',
]
```

or

```php
[
    'ok' => false,
    'reason' => 'transcript_unavailable',
]
```

- [ ] **Step 6: Re-run the unit tests**

Run:

- `php artisan test tests/Unit/Services/EmailLinkExtractorTest.php`
- `php artisan test tests/Unit/Services/YouTubeTranscriptServiceTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Email/EmailLinkExtractor.php app/Services/Email/YouTubeTranscriptService.php tests/Unit/Services/EmailLinkExtractorTest.php tests/Unit/Services/YouTubeTranscriptServiceTest.php dev/2026-03-21-youtube-transcript-validation.md
git commit -m "feat: add email link and transcript extraction services"
```

---

### Task 5.2: Build the best-effort newsletter research service

**Files:**
- Create: `app/Services/Email/EmailNewsletterResearchService.php`
- Modify: `app/Services/ThoughtCaptureService.php`
- Test: `tests/Unit/Services/EmailNewsletterResearchServiceTest.php`

- [ ] **Step 1: Write the failing research-service tests**

Create `tests/Unit/Services/EmailNewsletterResearchServiceTest.php` covering:

- body + links create a research document
- transcript text is included when available
- transcript failure still saves research when other inputs are sufficient
- insufficient content skips research with a machine-readable reason
- created research links back to the stored email record and the email thought
- created email thought `source_metadata` records:
  - sender rule action
  - stored email record id
  - linked research thought id when available
- created research thought `source_metadata` records:
  - stored email record id
  - email thought id
  - sender email
  - ingestion source

- [ ] **Step 2: Run the unit tests**

Run: `php artisan test tests/Unit/Services/EmailNewsletterResearchServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement the service**

Responsibilities:

- gather body text, subject, sender, links, and transcripts
- decide whether input is sufficient
- build one structured markdown research document
- save it through `ThoughtCaptureService` using:
  - `source = 'research'`
  - `doc_type = 'research'`
  - `plan_slug` derived from stored email id or message id
- `project = config('app.name', 'ideatub')`

- [ ] **Step 4: Add any needed `ThoughtCaptureService` extension point**

Only if necessary, extend `ThoughtCaptureService` so the saved research can carry:

- stored email record id
- email thought id
- sender email
- ingestion source

Do not create a separate raw `Thought::create()` path unless the capture service truly cannot support the needed metadata.

- [ ] **Step 5: Re-run the research-service tests**

Run: `php artisan test tests/Unit/Services/EmailNewsletterResearchServiceTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Email/EmailNewsletterResearchService.php app/Services/ThoughtCaptureService.php tests/Unit/Services/EmailNewsletterResearchServiceTest.php
git commit -m "feat: add newsletter research document capture"
```

---

### Task 5.3: Add the async research job and dispatch it from both ingestion paths

**Files:**
- Create: `app/Jobs/ProcessExtraEmailResearch.php`
- Modify: `app/Services/PostmarkInboundService.php`
- Modify: `app/Services/Email/EmailImportService.php`
- Test: `tests/Feature/ProcessExtraEmailResearchJobTest.php`
- Test: `tests/Feature/PostmarkInboundWebhookTest.php`
- Test: `tests/Feature/BackfillMailAccountJobTest.php`

- [ ] **Step 1: Write the failing job tests**

Create `tests/Feature/ProcessExtraEmailResearchJobTest.php` covering:

- job creates research for a stored email with usable content
- job records partial failure metadata when transcript fetch fails
- job skips research cleanly when input is insufficient
- job links the research thought back to the stored email and email thought
- replaying the same job for the same stored email does not create a second research thought during ordinary processing

- [ ] **Step 2: Run the job test**

Run: `php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php`

Expected: FAIL because the job does not exist.

- [ ] **Step 3: Implement the job**

Create `ProcessExtraEmailResearch` with:

- constructor payload that can resolve either `ImportedEmail` or `CapturedInboundEmail`
- `tries`, `backoff`, and `timeout`
- job `handle()` that calls `EmailNewsletterResearchService`
- persistence of:
  - `research_thought_id`
  - `processing_status`
  - `processing_metadata_json`
- email-thought metadata refresh so linked thoughts can expose research status and linked research id without changing the original email content

- [ ] **Step 4: Dispatch the job from both ingestion paths**

Update:

- `EmailImportService`
- `PostmarkInboundService`

so `extra_process` dispatches the job only after the email thought has been created successfully.

- [ ] **Step 5: Add dispatch assertions to existing feature tests**

Update:

- `tests/Feature/PostmarkInboundWebhookTest.php`
- `tests/Feature/BackfillMailAccountJobTest.php`

to use `Bus::fake()` and assert `ProcessExtraEmailResearch` dispatches exactly once for `extra_process`.

- [ ] **Step 6: Re-run the job and feature tests**

Run:

- `php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php`
- `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`
- `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ProcessExtraEmailResearch.php app/Services/PostmarkInboundService.php app/Services/Email/EmailImportService.php tests/Feature/ProcessExtraEmailResearchJobTest.php tests/Feature/PostmarkInboundWebhookTest.php tests/Feature/BackfillMailAccountJobTest.php
git commit -m "feat: queue extra email research"
```

---

### Task 5.4: Expose lightweight research status on email thoughts

**Files:**
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Create: `tests/Feature/EmailThoughtStatusDisplayTest.php`

- [ ] **Step 1: Write the failing status-display tests**

Create `tests/Feature/EmailThoughtStatusDisplayTest.php` covering:

- email thought with `source_metadata.research_status = research_queued` shows a queued badge
- email thought with `research_partial` shows a partial badge
- email thought with linked `research_thought_id` shows a completed badge or link
- non-email thoughts do not render the status UI

- [ ] **Step 2: Run the feature test**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: FAIL because no status UI exists yet.

- [ ] **Step 3: Render the lightweight status badges**

Update the recent-thought and stream card partials to show a small email-status badge only when:

- `source = email`
- `source_metadata.research_status` is present

Support:

- queued
- completed
- partial
- skipped
- failed

- [ ] **Step 4: Re-run the feature test**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php tests/Feature/EmailThoughtStatusDisplayTest.php
git commit -m "feat: show email research status on thought cards"
```

---

## Chunk 6: Final verification and cleanup

### Task 6.1: Verify the full sender-rule and research slice

**Files:**
- Verify only

- [ ] **Step 1: Run focused tests**

Run:

```bash
php artisan test tests/Feature/EmailSenderRuleSettingsTest.php
php artisan test tests/Unit/Services/EmailSenderRuleServiceTest.php
php artisan test tests/Unit/Services/EmailImportServiceTest.php
php artisan test tests/Feature/PostmarkInboundWebhookTest.php
php artisan test tests/Feature/BackfillMailAccountJobTest.php
php artisan test tests/Feature/EmailReviewInboxTest.php
php artisan test tests/Feature/InboxActionsTest.php
php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php
php artisan test tests/Unit/Services/EmailLinkExtractorTest.php
php artisan test tests/Unit/Services/YouTubeTranscriptServiceTest.php
php artisan test tests/Unit/Services/EmailNewsletterResearchServiceTest.php
php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php
```

Expected: PASS.

- [ ] **Step 2: Run broader regression checks**

Run:

```bash
php artisan test tests/Feature/EmailAccountSettingsTest.php
php artisan test
```

Expected: PASS, or only known unrelated failures that already existed before this feature work started.

- [ ] **Step 3: Manual verification**

1. Add a sender rule for `natesnewsletter@substack.com` with `extra_process`.
2. Send or import a matching email with at least one YouTube link.
3. Confirm the original email is stored and the email thought appears.
4. Confirm the research job runs and creates a linked research document.
5. Confirm transcript failure still leaves the original email and creates degraded research when enough input remains.
6. Import an unknown sender and confirm it lands in Inbox review without creating a thought.
7. Apply a review action and confirm the sender rule is saved and the Inbox item resolves.

- [ ] **Step 4: Final commit**

```bash
git status
git add config/services.php app/Models/EmailSenderRule.php app/Models/CapturedInboundEmail.php app/Http/Controllers/EmailSenderRuleSettingsController.php app/Services/Email app/Services/PostmarkInboundService.php app/Jobs/ProcessExtraEmailResearch.php database/migrations resources/views/settings/email-sender-rules.blade.php resources/views/inbox/index.blade.php resources/views/layouts/idea.blade.php routes/web.php tests docs/superpowers/plans/2026-03-21-email-sender-rules-and-research.md dev/2026-03-21-youtube-transcript-validation.md
git commit -m "feat: add email sender rules and newsletter research"
```

---

## Execution handoff

Before implementation begins, create an isolated worktree for this feature so email-ingestion and Inbox changes do not collide with other in-flight specs or app work.

Plan complete and saved to `docs/superpowers/plans/2026-03-21-email-sender-rules-and-research.md`. Ready to execute?

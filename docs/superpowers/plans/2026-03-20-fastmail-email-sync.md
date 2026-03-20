# Fastmail Email Sync Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Fastmail-first mailbox sync feature that lets a user connect an account, backfill qualifying email history, keep syncing new mail, and store imported messages as `source = 'email'` thoughts with durable sync records.

**Architecture:** Add a new mail-sync subsystem with three persistent models (`MailAccount`, `MailSyncRun`, `ImportedEmail`), a thin Fastmail connector, and queued backfill/incremental jobs. Reuse existing IdeaTub settings, queue, and sync-status patterns from the Jira and inbound-email features, while keeping mailbox sync separate from the Postmark inbound capture flow.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, queued jobs, scheduled commands, PHPUnit-style feature and unit tests, Laravel HTTP fakes, encrypted Eloquent casts, `OpenRouterService`, `Thought`, `UserPreference`.

**Spec:** `docs/superpowers/specs/2026-03-20-fastmail-email-sync-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `dev/2026-03-20-fastmail-jmap-validation.md` | Record the confirmed Fastmail auth/session flow, alias discovery approach, and any API constraints before shipping code that depends on them. |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_mail_accounts_table.php` | Create `mail_accounts` with encrypted credentials/settings/checkpoint storage and per-user provider records. |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_mail_sync_runs_table.php` | Create `mail_sync_runs` for queued backfill/incremental observability. |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_imported_emails_table.php` | Create `imported_emails` with durable provider identity, filter status, retry fields, and nullable thought linkage. |
| `database/factories/MailAccountFactory.php` | Factory for owner-scoped mail-account tests. |
| `app/Models/MailAccount.php` | Mail account model with encrypted casts, relationships, and account status helpers. |
| `app/Models/MailSyncRun.php` | Sync-run model with account relationship and helpers for run status. |
| `app/Models/ImportedEmail.php` | Imported-email model with casts, thought/account relationships, and status helpers. |
| `app/Models/User.php` | Add `mailAccounts()` relationship. |
| `app/Models/Thought.php` | On delete, clear `imported_emails.thought_id` and stamp `thought_deleted_at` for email-backed thoughts. |
| `app/Exceptions/InvalidMailAccountCredentialsException.php` | Distinguish re-auth failures from generic sync failures so jobs can set `status = needs_reauth`. |
| `app/Http/Controllers/EmailAccountSettingsController.php` | Render the Email Accounts page, save/update Fastmail accounts, disconnect accounts, queue backfill, and queue manual incremental sync. |
| `resources/views/settings/email-accounts.blade.php` | Settings UI for Fastmail connection, backfill window, sync toggles, latest run status, and action buttons. |
| `resources/views/layouts/idea.blade.php` | Add the Email Accounts link in the user menu. |
| `routes/web.php` | Register authenticated settings routes for email account management and sync actions. |
| `config/services.php` | Add `mail_sync` configuration such as feature flag and batch sizes. |
| `.env.example` | Document the mail-sync feature flag and batch-size env vars if config is introduced. |
| `app/Services/Fastmail/FastmailHttpClient.php` | Low-level Fastmail HTTP/JMAP wrapper using the validated auth/session flow. |
| `app/Services/Fastmail/FastmailConnector.php` | Provider-specific adapter that validates credentials, lists mailboxes/aliases, and fetches normalized message batches plus checkpoints. |
| `app/Services/Email/NormalizedEmailMessage.php` | Small value object/array wrapper for normalized email message data used by the import pipeline. |
| `app/Services/Email/ParticipantNormalizer.php` | Canonicalize `from`/`to`/`cc` headers into a stable participant list. |
| `app/Services/Email/EmailBodyCleanupService.php` | Convert HTML to text, strip quoted replies/signatures, and remove obvious BCC-style header remnants. |
| `app/Services/Email/EmailFilterService.php` | Apply the default inclusion/exclusion heuristics and return filter reasons for sticky filtered rows. |
| `app/Services/Email/EmailImportService.php` | Own idempotency, `ImportedEmail` persistence, AI summary/metadata extraction, thought creation, retries, and sticky filtered behavior. |
| `app/Jobs/BackfillMailAccount.php` | Process historical batches for one account, create/update `MailSyncRun`, and persist the latest provider checkpoint. |
| `app/Jobs/SyncMailAccountIncremental.php` | Poll for new/changed mail for one account from the stored checkpoint and import it incrementally. |
| `app/Console/Commands/SyncAllMailAccountsCommand.php` | Dispatch incremental sync jobs for active mail accounts on the scheduler. |
| `routes/console.php` | Schedule the new mail-sync command. |
| `tests/Feature/EmailAccountSettingsTest.php` | Web tests for the Email Accounts page, connect/update/disconnect behavior, and queue dispatch. |
| `tests/Feature/ImportedEmailSchemaTest.php` | Schema-level coverage for imported-email uniqueness and nullable thought bookkeeping. |
| `tests/Feature/SyncAllMailAccountsCommandTest.php` | Command-level dispatch tests for scheduled incremental sync. |
| `tests/Feature/BackfillMailAccountJobTest.php` | Integration-style job tests covering imported rows, filtered rows, retries, and thought creation. |
| `tests/Feature/DeleteThoughtTest.php` | Extend delete coverage so email-backed thought deletion clears imported-email linkage without recreating thoughts. |
| `tests/Unit/Services/FastmailConnectorTest.php` | Unit tests for Fastmail auth validation, alias discovery, mailbox listing, and batch normalization. |
| `tests/Unit/Services/EmailBodyCleanupServiceTest.php` | Unit tests for quoted-reply/signature cleanup. |
| `tests/Unit/Services/EmailFilterServiceTest.php` | Unit tests for default inclusion/exclusion rules and sticky filter reasons. |
| `tests/Unit/Services/EmailImportServiceTest.php` | Unit tests for idempotency, retry behavior, sticky filtered rows, and thought creation metadata. |

---

## Chunk 1: Preflight and persistent schema

### Task 1.1: Validate Fastmail auth/session assumptions before coding

**Files:**
- Create: `dev/2026-03-20-fastmail-jmap-validation.md`
- Reference: `docs/superpowers/specs/2026-03-20-fastmail-email-sync-design.md`

- [ ] **Step 1: Capture the validation checklist in a dev note**

Create `dev/2026-03-20-fastmail-jmap-validation.md` with headings for:

- auth method actually supported for the chosen Fastmail/JMAP flow
- session-discovery endpoint and required headers
- alias discovery source
- mailbox listing call
- incremental change/checkpoint call
- rate-limit or batch-size constraints

- [ ] **Step 2: Manually validate the flow outside production code**

Use a one-off local script or curl-based scratchpad to confirm:

- how credentials are exchanged or presented
- how the session URL is discovered
- how aliases are returned
- which message identity/checkpoint fields are stable

Expected: a short written answer exists for each heading in the dev note.

- [ ] **Step 3: Record implementation decisions**

In the same dev note, add:

- the exact auth path IdeaTub will support in v1
- the exact alias field(s) to trust
- the exact checkpoint/change field(s) to persist in `provider_checkpoint_json`
- any provider limitations that the code must respect

- [ ] **Step 4: Commit**

```bash
git add dev/2026-03-20-fastmail-jmap-validation.md
git commit -m "Document Fastmail JMAP validation"
```

---

### Task 1.2: Add failing schema/model tests for mail accounts

**Files:**
- Create: `tests/Feature/EmailAccountSettingsTest.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Write the failing settings page/auth tests**

Create `tests/Feature/EmailAccountSettingsTest.php` with at least:

```php
public function test_email_accounts_page_requires_auth(): void
{
    $response = $this->get(route('settings.email-accounts.index'));

    $response->assertRedirect(route('login'));
}

public function test_authenticated_user_sees_email_accounts_page(): void
{
    $user = User::factory()->create(['email' => 'user@example.com']);

    $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

    $response->assertOk();
    $response->assertSee('Email Accounts');
    $response->assertSee('Fastmail');
}
```

- [ ] **Step 2: Run the tests to verify route/model failures**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php`

Expected: FAIL because the route/controller/view/model work does not exist yet.

- [ ] **Step 3: Add the `mailAccounts()` relationship stub**

In `app/Models/User.php`, add:

```php
public function mailAccounts()
{
    return $this->hasMany(MailAccount::class);
}
```

Also add `use App\Models\MailAccount;` if needed.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/EmailAccountSettingsTest.php app/Models/User.php
git commit -m "test: add email account settings entry tests"
```

---

### Task 1.3: Create `mail_accounts` migration and model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_mail_accounts_table.php`
- Create: `database/factories/MailAccountFactory.php`
- Create: `app/Models/MailAccount.php`
- Test: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Extend the failing feature test to assert persistence**

Add a test:

```php
public function test_user_can_store_fastmail_account(): void
{
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $response = $this->actingAs($user)->post(route('settings.email-accounts.store'), [
        'provider' => 'fastmail',
        'display_name' => 'Primary Fastmail',
        'account_email' => 'owner@fastmail.fm',
        'credential' => 'secret-value',
        'sync_enabled' => '1',
        'include_sent' => '1',
        'include_received_personal' => '1',
        'exclude_bulk' => '1',
        'initial_backfill_window_days' => '90',
    ]);

    $response->assertRedirect(route('settings.email-accounts.index'));
    $this->assertDatabaseHas('mail_accounts', [
        'user_id' => $user->id,
        'provider' => 'fastmail',
        'account_email' => 'owner@fastmail.fm',
    ]);
}
```

- [ ] **Step 2: Run the single failing test**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_user_can_store_fastmail_account`

Expected: FAIL because the table/model/routes do not exist.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_mail_accounts_table`

Implement:

```php
Schema::create('mail_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('provider', 50);
    $table->string('display_name', 255);
    $table->string('account_email', 255);
    $table->string('status', 50)->default('active');
    $table->json('credentials_json');
    $table->json('settings_json')->nullable();
    $table->json('provider_checkpoint_json')->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamp('last_successful_sync_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'provider']);
});
```

- [ ] **Step 4: Create the model with encrypted casts**

Create `app/Models/MailAccount.php` with:

- `fillable` for the persisted fields
- casts:
  - `'credentials_json' => 'encrypted:array'`
  - `'settings_json' => 'array'`
  - `'provider_checkpoint_json' => 'array'`
  - timestamps/status helpers
- `user()`, `syncRuns()`, and `importedEmails()` relationships

- [ ] **Step 5: Create the factory**

Create `database/factories/MailAccountFactory.php` with defaults for:

- `provider = 'fastmail'`
- `display_name = 'Primary Fastmail'`
- `account_email = fake()->safeEmail()`
- `status = 'active'`
- `credentials_json = ['credential' => 'secret']`
- `settings_json = ['sync_enabled' => true, 'include_sent' => true, 'include_received_personal' => true, 'exclude_bulk' => true, 'initial_backfill_window_days' => 90]`

Use `User::factory()` for `user_id`.

- [ ] **Step 6: Run migrations and the feature test**

Run:

- `php artisan migrate`
- `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_user_can_store_fastmail_account`

Expected: still FAIL, but now because the controller/routes/view are missing instead of the schema/model.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*_create_mail_accounts_table.php database/factories/MailAccountFactory.php app/Models/MailAccount.php app/Models/User.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "feat: add mail account schema"
```

---

### Task 1.4: Create `mail_sync_runs` and `imported_emails`

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_mail_sync_runs_table.php`
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_imported_emails_table.php`
- Create: `app/Models/MailSyncRun.php`
- Create: `app/Models/ImportedEmail.php`
- Create: `tests/Feature/ImportedEmailSchemaTest.php`

- [ ] **Step 1: Write the failing schema test**

Create `tests/Feature/ImportedEmailSchemaTest.php` with:

```php
public function test_provider_message_id_is_unique_per_mail_account(): void
{
    $account = MailAccount::factory()->create();
    ImportedEmail::create([
        'user_id' => $account->user_id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'msg-123',
        'direction' => 'sent',
        'processing_status' => 'pending',
    ]);

    $this->expectException(QueryException::class);

    ImportedEmail::create([
        'user_id' => $account->user_id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'msg-123',
        'direction' => 'sent',
        'processing_status' => 'pending',
    ]);
}
```

- [ ] **Step 2: Run the schema test to verify it fails**

Run: `php artisan test tests/Feature/ImportedEmailSchemaTest.php --filter test_provider_message_id_is_unique_per_mail_account`

Expected: FAIL because the tables/models do not exist yet.

- [ ] **Step 3: Create the sync-run migration/model**

Run: `php artisan make:migration create_mail_sync_runs_table`

Implement:

```php
Schema::create('mail_sync_runs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
    $table->string('run_type', 20);
    $table->string('status', 50)->default('queued');
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->json('stats_json')->nullable();
    $table->text('error_summary')->nullable();
    $table->timestamps();
});
```

Create `app/Models/MailSyncRun.php` with casts for `stats_json`, `started_at`, and `finished_at`.

- [ ] **Step 4: Create the imported-email migration/model**

Run: `php artisan make:migration create_imported_emails_table`

Implement:

```php
Schema::create('imported_emails', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('mail_sync_run_id')->nullable()->constrained()->nullOnDelete();
    $table->string('provider', 50);
    $table->string('provider_message_id', 255);
    $table->string('provider_thread_id', 255)->nullable();
    $table->string('provider_mailbox_id', 255)->nullable();
    $table->string('provider_mailbox_name', 255)->nullable();
    $table->string('direction', 20);
    $table->string('subject', 1024)->nullable();
    $table->json('from_json')->nullable();
    $table->json('to_json')->nullable();
    $table->json('cc_json')->nullable();
    $table->json('participants_json')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->longText('body_text')->nullable();
    $table->text('summary')->nullable();
    $table->json('message_metadata_json')->nullable();
    $table->string('content_fingerprint', 64)->nullable();
    $table->foreignUuid('thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
    $table->timestamp('thought_deleted_at')->nullable();
    $table->string('processing_status', 20)->default('pending');
    $table->unsignedInteger('failure_count')->default(0);
    $table->text('failure_reason')->nullable();
    $table->timestamps();

    $table->unique(['mail_account_id', 'provider_message_id']);
    $table->index(['user_id', 'processing_status']);
});
```

Create `app/Models/ImportedEmail.php` with casts for JSON arrays and timestamps plus `mailAccount()`, `syncRun()`, and `thought()` relations.

- [ ] **Step 5: Run migrations**

Run: `php artisan migrate`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/*_create_mail_sync_runs_table.php database/migrations/*_create_imported_emails_table.php app/Models/MailSyncRun.php app/Models/ImportedEmail.php tests/Feature/ImportedEmailSchemaTest.php
git commit -m "feat: add mail sync run and imported email schema"
```

---

### Task 1.5: Add mail-sync config placeholders

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add the failing config assertion test**

Append to `tests/Feature/EmailAccountSettingsTest.php`:

```php
public function test_email_accounts_page_respects_feature_flag(): void
{
    config(['services.mail_sync.enabled' => false]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

    $response->assertNotFound();
}
```

- [ ] **Step 2: Run the failing test**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_email_accounts_page_respects_feature_flag`

Expected: FAIL because the feature flag/config/routes do not exist yet.

- [ ] **Step 3: Add config and env placeholders**

In `config/services.php`, add:

```php
'mail_sync' => [
    'enabled' => filter_var(env('MAIL_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'backfill_batch_size' => (int) env('MAIL_SYNC_BACKFILL_BATCH_SIZE', 50),
    'incremental_batch_size' => (int) env('MAIL_SYNC_INCREMENTAL_BATCH_SIZE', 25),
],
```

In `.env.example`, add:

```bash
MAIL_SYNC_ENABLED=true
MAIL_SYNC_BACKFILL_BATCH_SIZE=50
MAIL_SYNC_INCREMENTAL_BATCH_SIZE=25
```

- [ ] **Step 4: Commit**

```bash
git add config/services.php .env.example tests/Feature/EmailAccountSettingsTest.php
git commit -m "chore: add mail sync config placeholders"
```

---

## Chunk 2: Email account settings UI

### Task 2.1: Add settings routes and controller skeleton

**Files:**
- Create: `app/Http/Controllers/EmailAccountSettingsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Expand the failing feature tests**

Add tests for:

- GET page returns 404 when `services.mail_sync.enabled` is false
- POST store redirects back with validation errors when required fields are missing
- DELETE disconnect removes the account
- DELETE disconnect returns 403 when another user owns the account
- POST backfill returns 403 when another user owns the account
- POST sync-now returns 403 when another user owns the account
- POST backfill queues the job
- POST sync-now queues the incremental job

Use `Bus::fake()` for the job-dispatch assertions.

- [ ] **Step 2: Run the feature test file**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php`

Expected: FAIL because routes/controller do not exist.

- [ ] **Step 3: Add authenticated routes**

In `routes/web.php`, inside the `auth` group and guarded by `config('services.mail_sync.enabled', true)`:

```php
Route::get('/settings/email-accounts', [EmailAccountSettingsController::class, 'index'])->name('settings.email-accounts.index');
Route::post('/settings/email-accounts', [EmailAccountSettingsController::class, 'store'])->name('settings.email-accounts.store');
Route::delete('/settings/email-accounts/{mailAccount}', [EmailAccountSettingsController::class, 'destroy'])->name('settings.email-accounts.destroy');
Route::post('/settings/email-accounts/{mailAccount}/backfill', [EmailAccountSettingsController::class, 'backfill'])->name('settings.email-accounts.backfill');
Route::post('/settings/email-accounts/{mailAccount}/sync', [EmailAccountSettingsController::class, 'syncNow'])->name('settings.email-accounts.sync');
```

- [ ] **Step 4: Add controller skeleton**

Create `EmailAccountSettingsController` with methods:

- `index(Request $request): View`
- `store(Request $request): RedirectResponse`
- `destroy(Request $request, MailAccount $mailAccount): RedirectResponse`
- `backfill(Request $request, MailAccount $mailAccount): RedirectResponse`
- `syncNow(Request $request, MailAccount $mailAccount): RedirectResponse`

Use owner-only authorization via direct user-id checks (matching current controller style) unless a dedicated policy becomes necessary.

- [ ] **Step 5: Re-run the feature tests**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php`

Expected: FAIL inside controller/view logic rather than route resolution.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/EmailAccountSettingsController.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "feat: add email account settings routes"
```

---

### Task 2.2: Implement account save/update/disconnect behavior

**Files:**
- Create: `app/Exceptions/InvalidMailAccountCredentialsException.php`
- Modify: `app/Http/Controllers/EmailAccountSettingsController.php`
- Create: `tests/Unit/Services/FastmailConnectorTest.php`
- Test: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Write the failing connector validation test**

Create `tests/Unit/Services/FastmailConnectorTest.php` with:

```php
public function test_validate_credentials_returns_normalized_account_details(): void
{
    Http::fake([
        '*' => Http::response([
            'account_email' => 'owner@fastmail.fm',
            'aliases' => ['owner+alias@fastmail.fm'],
        ], 200),
    ]);

    $connector = app(FastmailConnector::class);
    $result = $connector->validateCredentials([
        'account_email' => 'owner@fastmail.fm',
        'credential' => 'secret',
    ]);

    $this->assertSame('owner@fastmail.fm', $result['account_email']);
}
```

- [ ] **Step 2: Run the connector/unit test**

Run: `php artisan test tests/Unit/Services/FastmailConnectorTest.php`

Expected: FAIL because the connector does not exist.

- [ ] **Step 3: Implement `store()` in the controller**

Behavior:

- validate `provider`, `display_name`, `account_email`, `credential`, `sync_enabled`, `include_sent`, `include_received_personal`, `exclude_bulk`, `initial_backfill_window_days`
- call `FastmailConnector::validateCredentials(...)`
- create or update one `mail_accounts` row for the user/account email/provider
- persist:
  - `credentials_json`
  - `settings_json`
  - `status = active`
- redirect with success flash

On validation/connect failure, redirect back with `error` and keep safe non-secret input only.

- [ ] **Step 4: Add the credential exception type**

Create `app/Exceptions/InvalidMailAccountCredentialsException.php` and have `FastmailConnector::validateCredentials(...)` throw it for invalid, expired, or revoked credentials.

- [ ] **Step 5: Implement `destroy()`**

Delete the account row for the owner and redirect with success. Allow cascade delete to remove sync runs/imported emails.

- [ ] **Step 6: Re-run the relevant tests**

Run:

- `php artisan test tests/Unit/Services/FastmailConnectorTest.php`
- `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_user_can_store_fastmail_account`
- `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_user_can_disconnect_account`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Exceptions/InvalidMailAccountCredentialsException.php app/Http/Controllers/EmailAccountSettingsController.php tests/Unit/Services/FastmailConnectorTest.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "feat: save Fastmail accounts from settings"
```

---

### Task 2.3: Render the Email Accounts settings page

**Files:**
- Create: `resources/views/settings/email-accounts.blade.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `app/Http/Controllers/EmailAccountSettingsController.php`
- Test: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Extend the feature test expectations**

Assert that the page shows:

- `Email Accounts`
- `Fastmail`
- a disclosure that synced email content is sent through the configured AI pipeline for summaries and metadata extraction
- stored account email
- a backfill window selector
- `Run backfill`
- `Sync now`
- latest run status text when a `MailSyncRun` exists

- [ ] **Step 2: Run the feature tests**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter test_authenticated_user_sees_email_accounts_page`

Expected: FAIL because the view/nav content does not exist.

- [ ] **Step 3: Build the Blade view**

Follow the styling pattern from `resources/views/settings/jira.blade.php` and `resources/views/settings/inbound-emails.blade.php`.

Render:

- connection form
- AI-processing disclosure copy near the credential form
- saved account cards
- latest sync status from `$account->syncRuns()->latest()->first()`
- backfill button
- sync-now button
- disconnect button

- [ ] **Step 4: Add the nav link**

In `resources/views/layouts/idea.blade.php`, add:

```blade
@if (config('services.mail_sync.enabled', true))
<a href="{{ route('settings.email-accounts.index') }}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
    Email Accounts
</a>
@endif
```

- [ ] **Step 5: Re-run the feature tests**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php`

Expected: PASS for page-rendering and auth/navigation assertions.

- [ ] **Step 6: Commit**

```bash
git add resources/views/settings/email-accounts.blade.php resources/views/layouts/idea.blade.php app/Http/Controllers/EmailAccountSettingsController.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "feat: add Email Accounts settings page"
```

---

## Chunk 3: Fastmail connector and normalization helpers

### Task 3.1: Implement the low-level Fastmail HTTP client

**Files:**
- Create: `app/Services/Fastmail/FastmailHttpClient.php`
- Test: `tests/Unit/Services/FastmailConnectorTest.php`

- [ ] **Step 1: Add a failing HTTP-shape test**

Add a unit test that asserts the client sends the validated auth/session headers and parses the JSON body returned by the Fastmail/JMAP flow captured in `dev/2026-03-20-fastmail-jmap-validation.md`.

- [ ] **Step 2: Run the unit test**

Run: `php artisan test tests/Unit/Services/FastmailConnectorTest.php --filter test_fastmail_http_client_sends_validated_session_request`

Expected: FAIL because the client does not exist.

- [ ] **Step 3: Implement the client**

Create methods like:

- `discoverSession(array $credentials): array`
- `request(array $credentials, array $payload): array`

Use Laravel `Http` so tests can use `Http::fake()`.

- [ ] **Step 4: Re-run the unit test**

Run: `php artisan test tests/Unit/Services/FastmailConnectorTest.php --filter test_fastmail_http_client_sends_validated_session_request`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Fastmail/FastmailHttpClient.php tests/Unit/Services/FastmailConnectorTest.php
git commit -m "feat: add Fastmail HTTP client"
```

---

### Task 3.2: Implement `FastmailConnector`

**Files:**
- Create: `app/Services/Fastmail/FastmailConnector.php`
- Modify: `app/Exceptions/InvalidMailAccountCredentialsException.php`
- Modify: `tests/Unit/Services/FastmailConnectorTest.php`

- [ ] **Step 1: Add failing tests for connector behavior**

Cover:

- credential validation returns normalized account email and aliases
- mailbox listing returns mailbox ids/names
- `fetchBackfillBatch()` returns normalized messages plus `next_checkpoint`
- `fetchIncrementalBatch()` returns normalized messages plus `next_checkpoint`
- invalid credentials raise `InvalidMailAccountCredentialsException`

- [ ] **Step 2: Run the connector test file**

Run: `php artisan test tests/Unit/Services/FastmailConnectorTest.php`

Expected: FAIL because the connector does not exist.

- [ ] **Step 3: Implement the connector**

Add methods:

- `validateCredentials(array $input): array`
- `listMailboxes(MailAccount $account): array`
- `fetchBackfillBatch(MailAccount $account, array $options): array`
- `fetchIncrementalBatch(MailAccount $account): array`

Normalize the returned batch to:

```php
[
    'messages' => [new NormalizedEmailMessage(/* ... */)],
    'next_checkpoint' => [...],
]
```

Use the dev note as the source of truth for alias lookup and checkpoint fields.

When credentials are invalid, throw `InvalidMailAccountCredentialsException` so controllers/jobs can set `mail_accounts.status = needs_reauth` or show an error instead of treating it as a generic failure.

- [ ] **Step 4: Re-run the connector tests**

Run: `php artisan test tests/Unit/Services/FastmailConnectorTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Fastmail/FastmailConnector.php tests/Unit/Services/FastmailConnectorTest.php
git commit -m "feat: add Fastmail connector"
```

---

### Task 3.3: Add message normalization, cleanup, and filtering helpers

**Files:**
- Create: `app/Services/Email/NormalizedEmailMessage.php`
- Create: `app/Services/Email/ParticipantNormalizer.php`
- Create: `app/Services/Email/EmailBodyCleanupService.php`
- Create: `app/Services/Email/EmailFilterService.php`
- Create: `tests/Unit/Services/EmailBodyCleanupServiceTest.php`
- Create: `tests/Unit/Services/EmailFilterServiceTest.php`

- [ ] **Step 1: Write the failing cleanup tests**

In `tests/Unit/Services/EmailBodyCleanupServiceTest.php`, cover:

- HTML body converted to text
- quoted reply chain removed
- signature block removed
- obvious `Bcc:` header text removed when detectable

- [ ] **Step 2: Write the failing filter tests**

In `tests/Unit/Services/EmailFilterServiceTest.php`, cover:

- sent mail included
- directly addressed received mail included when account email or alias is in `To`/`Cc`
- no-reply and bulk mail excluded
- filtered result returns a stable machine-readable reason such as `bulk_sender` or `not_directly_addressed`

- [ ] **Step 3: Run the unit tests**

Run:

- `php artisan test tests/Unit/Services/EmailBodyCleanupServiceTest.php`
- `php artisan test tests/Unit/Services/EmailFilterServiceTest.php`

Expected: FAIL because the services do not exist.

- [ ] **Step 4: Implement the helpers**

Create:

- `NormalizedEmailMessage` as a small immutable class or readonly value object
- `ParticipantNormalizer` to output canonical header-derived participant arrays
- `EmailBodyCleanupService` to prepare text before filtering/AI
- `EmailFilterService` to return:

```php
[
    'include' => true,
    'reason' => null,
]
```

or

```php
[
    'include' => false,
    'reason' => 'bulk_sender',
]
```

- [ ] **Step 5: Re-run the unit tests**

Run:

- `php artisan test tests/Unit/Services/EmailBodyCleanupServiceTest.php`
- `php artisan test tests/Unit/Services/EmailFilterServiceTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Email/NormalizedEmailMessage.php app/Services/Email/ParticipantNormalizer.php app/Services/Email/EmailBodyCleanupService.php app/Services/Email/EmailFilterService.php tests/Unit/Services/EmailBodyCleanupServiceTest.php tests/Unit/Services/EmailFilterServiceTest.php
git commit -m "feat: add email cleanup and filtering services"
```

---

## Chunk 4: Import pipeline and thought linkage

### Task 4.1: Implement `EmailImportService` row persistence and filtering

**Files:**
- Create: `app/Services/Email/EmailImportService.php`
- Modify: `tests/Unit/Services/EmailImportServiceTest.php`

- [ ] **Step 1: Expand the failing import-service tests**

Cover:

- same provider message id is stored once per account
- filtered messages create one `imported_emails` row with `processing_status = filtered`
- filtered classification is sticky on normal replay
- failed imports increment `failure_count` on the same row

- [ ] **Step 2: Run the unit test file**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement the service**

Flow:

1. Look up or create `ImportedEmail` by `(mail_account_id, provider_message_id)`
2. If an existing row is `filtered`, short-circuit for normal replay
3. Clean the message body
4. Normalize participants
5. Apply `EmailFilterService`
6. For excluded mail, store minimal metadata and the filter reason
7. For included mail, persist the prepared import row and return it to the caller without creating the thought yet
8. On failure, increment `failure_count`, store `failure_reason`, and keep the row retriable

- [ ] **Step 4: Re-run the unit tests**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Email/EmailImportService.php tests/Unit/Services/EmailImportServiceTest.php
git commit -m "feat: add email import service"
```

---

### Task 4.2: Create thoughts from included imports via `ThoughtCaptureService`

**Files:**
- Modify: `app/Services/Email/EmailImportService.php`
- Modify: `app/Services/ThoughtCaptureService.php`
- Modify: `tests/Unit/Services/EmailImportServiceTest.php`

- [ ] **Step 1: Extend the failing import-service tests**

Add assertions that included imports:

- create one thought with `source = 'email'`
- call `ThoughtCaptureService::create(...)` with `no_chunking = true`
- write `source_metadata` containing:
  - `provider`
  - `mail_account_id`
  - `imported_email_id`
  - `provider_message_id`
  - `provider_thread_id`
  - `direction`
  - `subject`
  - `sent_at`
  - `received_at`
  - `participants`
  - `provider_mailbox_name`
  - `mail_sync_run_id`

- [ ] **Step 2: Run the unit test file**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: FAIL because the thought-creation path is not implemented yet.

- [ ] **Step 3: Reuse the shared capture path**

Update the import flow so included messages:

- call `ThoughtCaptureService::create([...])` with `source = 'email'` and `no_chunking = true`
- reuse the captured thought as the durable thought record
- merge email-specific metadata onto the resulting thought after capture if `ThoughtCaptureService` needs a small extension point for post-capture metadata enrichment

Do not create email thoughts via a completely separate raw `Thought::create(...)` path unless the shared service proves impossible; keep email capture behavior aligned with Postmark wherever practical.

- [ ] **Step 4: Re-run the unit tests**

Run: `php artisan test tests/Unit/Services/EmailImportServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Email/EmailImportService.php app/Services/ThoughtCaptureService.php tests/Unit/Services/EmailImportServiceTest.php
git commit -m "feat: create imported email thoughts via shared capture flow"
```

---

### Task 4.3: Track imported-thought deletion

**Files:**
- Modify: `app/Models/Thought.php`
- Modify: `tests/Feature/DeleteThoughtTest.php`

- [ ] **Step 1: Add the failing delete test**

Append:

```php
public function test_deleting_email_backed_thought_clears_imported_email_link(): void
{
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'source_metadata' => ['provider_message_id' => 'msg-123'],
        'embedding' => null,
    ]);

    $imported = ImportedEmail::create([
        'user_id' => $owner->id,
        'mail_account_id' => MailAccount::factory()->for($owner)->create()->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'msg-123',
        'direction' => 'sent',
        'processing_status' => 'imported',
        'thought_id' => $thought->id,
    ]);

    $this->actingAs($owner)->deleteJson(route('ideas.destroy', $thought))->assertNoContent();

    $this->assertDatabaseHas('imported_emails', [
        'id' => $imported->id,
        'thought_id' => null,
    ]);
}
```

- [ ] **Step 2: Run the delete test**

Run: `php artisan test tests/Feature/DeleteThoughtTest.php --filter test_deleting_email_backed_thought_clears_imported_email_link`

Expected: FAIL because deletion bookkeeping is not implemented.

- [ ] **Step 3: Implement the deleted-hook bookkeeping**

In `app/Models/Thought.php`, add a `static::deleted(...)` hook that:

- only runs for `source === 'email'`
- clears `imported_emails.thought_id`
- sets `thought_deleted_at = now()`

Use `ImportedEmail::query()->where('thought_id', $thought->id)->update([...])`.

- [ ] **Step 4: Re-run the delete test**

Run: `php artisan test tests/Feature/DeleteThoughtTest.php --filter test_deleting_email_backed_thought_clears_imported_email_link`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Thought.php tests/Feature/DeleteThoughtTest.php
git commit -m "feat: preserve imported email state when thoughts are deleted"
```

---

## Chunk 5: Jobs, scheduling, and end-to-end sync behavior

### Task 5.1: Queue backfill and manual sync from settings

**Files:**
- Create: `app/Jobs/BackfillMailAccount.php`
- Create: `app/Jobs/SyncMailAccountIncremental.php`
- Modify: `app/Http/Controllers/EmailAccountSettingsController.php`
- Test: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Add the failing dispatch tests**

Use `Bus::fake()` to assert:

- storing a new account dispatches `BackfillMailAccount`
- posting to `settings.email-accounts.backfill` dispatches `BackfillMailAccount`
- posting to `settings.email-accounts.sync` dispatches `SyncMailAccountIncremental`

- [ ] **Step 2: Run the feature tests**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php --filter backfill`

Expected: FAIL because the jobs do not exist.

- [ ] **Step 3: Implement the jobs**

`BackfillMailAccount`:

- load the account
- create `MailSyncRun` with `run_type = backfill`, `status = running`
- fetch batches with `FastmailConnector`
- pass messages to `EmailImportService`
- update `provider_checkpoint_json`
- mark the run complete or failed

`SyncMailAccountIncremental`:

- same pattern, but `run_type = incremental`
- fetch only from `provider_checkpoint_json`

Set explicit queue properties like:

```php
public int $tries = 2;
public int $backoff = 120;
public int $timeout = 600;
```

If the connector raises `InvalidMailAccountCredentialsException`, update:

- `mail_accounts.status = 'needs_reauth'`
- the active `MailSyncRun` to `failed`

and surface a clear failure summary instead of retrying forever.

- [ ] **Step 4: Wire the controller actions**

In `store()`, dispatch initial `BackfillMailAccount` after saving the account.

In `backfill()` and `syncNow()`, enforce owner checks, dispatch the job, and redirect with a success flash.

- [ ] **Step 5: Re-run the feature tests**

Run: `php artisan test tests/Feature/EmailAccountSettingsTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/BackfillMailAccount.php app/Jobs/SyncMailAccountIncremental.php app/Http/Controllers/EmailAccountSettingsController.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "feat: queue mail backfill and incremental sync"
```

---

### Task 5.2: Add scheduled incremental sync command

**Files:**
- Create: `app/Console/Commands/SyncAllMailAccountsCommand.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/SyncAllMailAccountsCommandTest.php`

- [ ] **Step 1: Write the failing command test**

Create `tests/Feature/SyncAllMailAccountsCommandTest.php`:

```php
public function test_command_dispatches_incremental_sync_for_active_mail_accounts(): void
{
    Bus::fake();

    $user = User::factory()->create();
    MailAccount::create([
        'user_id' => $user->id,
        'provider' => 'fastmail',
        'display_name' => 'Primary',
        'account_email' => 'owner@fastmail.fm',
        'status' => 'active',
        'credentials_json' => ['credential' => 'secret'],
        'settings_json' => ['sync_enabled' => true],
    ]);

    $this->artisan('mail:sync-all')->assertExitCode(0);

    Bus::assertDispatched(SyncMailAccountIncremental::class);
}
```

- [ ] **Step 2: Run the command test**

Run: `php artisan test tests/Feature/SyncAllMailAccountsCommandTest.php`

Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command**

Match the style of `app/Console/Commands/SyncAllJiraActivityCommand.php`.

Behavior:

- no-op when `services.mail_sync.enabled` is false
- find active accounts with `settings_json->sync_enabled = true`
- dispatch `SyncMailAccountIncremental` once per account
- print the dispatched count

- [ ] **Step 4: Schedule the command**

In `routes/console.php`, add:

```php
Schedule::command('mail:sync-all')->hourly()->when(fn () => config('services.mail_sync.enabled', true));
```

- [ ] **Step 5: Re-run the command test**

Run: `php artisan test tests/Feature/SyncAllMailAccountsCommandTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncAllMailAccountsCommand.php routes/console.php tests/Feature/SyncAllMailAccountsCommandTest.php
git commit -m "feat: schedule incremental mail sync"
```

---

### Task 5.3: Add job-level integration tests

**Files:**
- Create: `tests/Feature/BackfillMailAccountJobTest.php`
- Modify: `app/Jobs/BackfillMailAccount.php`
- Modify: `app/Jobs/SyncMailAccountIncremental.php`

- [ ] **Step 1: Write the failing backfill job tests**

Cover:

- backfill imports a sent message and creates one thought
- received bulk/no-reply message becomes one sticky `filtered` row without a thought
- retrying a failed message increments `failure_count` on the same row
- incremental sync does not recreate a deleted thought when `thought_deleted_at` is set
- invalid credentials move the account to `needs_reauth` and mark the sync run failed

- [ ] **Step 2: Run the feature test**

Run: `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: FAIL because the jobs and import service are not yet wired end-to-end.

- [ ] **Step 3: Inject the connector/import service cleanly**

Refactor the jobs if needed so tests can mock:

- `FastmailConnector`
- `EmailImportService`

without reaching real provider APIs.

- [ ] **Step 4: Re-run the feature test**

Run: `php artisan test tests/Feature/BackfillMailAccountJobTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/BackfillMailAccountJobTest.php app/Jobs/BackfillMailAccount.php app/Jobs/SyncMailAccountIncremental.php
git commit -m "test: cover mail sync jobs end to end"
```

---

## Chunk 6: Final verification and cleanup

### Task 6.1: Verify the full mail-sync slice

**Files:**
- Verify only

- [ ] **Step 1: Run targeted tests**

Run:

```bash
php artisan test tests/Feature/EmailAccountSettingsTest.php
php artisan test tests/Feature/SyncAllMailAccountsCommandTest.php
php artisan test tests/Feature/BackfillMailAccountJobTest.php
php artisan test tests/Feature/DeleteThoughtTest.php
php artisan test tests/Unit/Services/FastmailConnectorTest.php
php artisan test tests/Unit/Services/EmailBodyCleanupServiceTest.php
php artisan test tests/Unit/Services/EmailFilterServiceTest.php
php artisan test tests/Unit/Services/EmailImportServiceTest.php
```

Expected: PASS.

- [ ] **Step 2: Run lint-adjacent verification**

Run: `php artisan test`

Expected: PASS, or only known unrelated failures if the suite is already red before this feature work starts.

- [ ] **Step 3: Manual verification**

In a local browser:

1. Open `Settings -> Email Accounts`
2. Save a Fastmail account with test credentials
3. Confirm a backfill job is queued and a `mail_sync_runs` row is created
4. Trigger `Sync now`
5. Confirm imported sent mail appears in Stream/Search with `source = email`
6. Delete one imported thought and confirm the matching `imported_emails` row keeps `thought_deleted_at` and does not recreate on the next sync

- [ ] **Step 4: Final commit**

```bash
git status
git add app config database resources routes tests dev .env.example
git commit -m "feat: add Fastmail email sync"
```

---

## Execution handoff

Before implementation begins, create an isolated worktree for this feature so mailbox-sync work does not collide with other in-flight docs or feature branches.

Plan complete and saved to `docs/superpowers/plans/2026-03-20-fastmail-email-sync.md`. Ready to execute?

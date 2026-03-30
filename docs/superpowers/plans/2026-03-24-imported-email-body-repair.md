# Imported Email Body Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix Fastmail email body fetching for future imports and add a targeted repair path for existing `imported_emails` rows missing `body_text` without recreating linked state.

**Architecture:** Keep the existing Fastmail sync and `EmailImportService` pipeline intact, but make `FastmailConnector` explicitly request JMAP body values and normalize text from `bodyValues` instead of relying on inline `textBody[].value`. Add a narrow single-message fetch path plus a conservative repair service/console command that only updates sync-side body fields on existing `ImportedEmail` rows.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, console commands, queued/sync-safe service patterns, PHPUnit/Pest via `php artisan test`, Fastmail JMAP connector, existing email cleanup/import services.

**Spec:** `docs/superpowers/specs/2026-03-24-imported-email-body-repair-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Services/Fastmail/FastmailConnector.php` | Request JMAP body values explicitly, normalize text from `bodyValues`, and expose single-message fetch by provider id. |
| `app/Services/Email/EmailImportService.php` | Remains the source of truth for normal import-side `content_fingerprint` semantics; reference only unless tests reveal a needed shared helper extraction. |
| `app/Services/Email/ImportedEmailBodyRepairService.php` | Repair one existing `ImportedEmail` row by refetching, cleaning, and updating allowed fields only. |
| `app/Console/Commands/RepairImportedEmailBodiesCommand.php` | Operator-facing command for dry-run and batched repair execution. |
| `tests/Unit/Services/FastmailConnectorTest.php` | Verify request shape and normalization for text-body and HTML-only messages. |
| `tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php` | Verify per-row repair behavior, skip rules, and linkage preservation. |
| `tests/Feature/RepairImportedEmailBodiesCommandTest.php` | Verify command selection, dry-run behavior, scoping, limits, and reporting. |
| `support/2026-03-24-imported-emails-missing-body-text.md` | Existing investigation note; reference only. |
| `docs/superpowers/specs/2026-03-24-imported-email-body-repair-design.md` | Approved design source for implementation decisions. |

### Architectural notes

- Do not delete or recreate `imported_emails` rows.
- Do not rewrite linked thought content in this slice.
- Do not refresh review/research metadata beyond optional `processing_metadata_json.body_repair`.
- Keep repair behavior idempotent and safe to rerun.

---

## Chunk 1: Fix Fastmail body fetching for future imports

### Task 1.1: Make the connector request JMAP body values explicitly

**Files:**
- Modify: `tests/Unit/Services/FastmailConnectorTest.php`
- Modify: `app/Services/Fastmail/FastmailConnector.php`

- [ ] **Step 1: Add the failing connector request-shape tests**

Extend `tests/Unit/Services/FastmailConnectorTest.php` with assertions that `Email/get` includes:

- `properties` containing `textBody`, `htmlBody`, and `bodyValues`
- `bodyProperties` containing at least `partId` and `type`
- `fetchTextBodyValues = true`
- `fetchHTMLBodyValues = true`

Example assertion shape:

```php
Http::assertSent(function ($request) {
    $emailGet = collect($request['methodCalls'] ?? [])
        ->first(fn (array $call): bool => ($call[0] ?? null) === 'Email/get');

    $args = $emailGet[1] ?? [];

    return $emailGet !== null
        && in_array('textBody', $args['properties'] ?? [], true)
        && in_array('htmlBody', $args['properties'] ?? [], true)
        && in_array('bodyValues', $args['properties'] ?? [], true)
        && in_array('partId', $args['bodyProperties'] ?? [], true)
        && in_array('type', $args['bodyProperties'] ?? [], true)
        && ($args['fetchTextBodyValues'] ?? false) === true
        && ($args['fetchHTMLBodyValues'] ?? false) === true;
});
```

- [ ] **Step 2: Run the focused connector tests and verify failure**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: FAIL because the current `Email/get` call only sends `accountId` and `#ids`.

- [ ] **Step 3: Update `FastmailConnector` request payloads**

In both:

- `fetchBackfillBatch()`
- `fetchIncrementalBatch()`

update `Email/get` to request explicit body values and part metadata.

- [ ] **Step 4: Re-run the connector tests**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: PASS for the request-shape assertions added in this task.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Fastmail/FastmailConnector.php tests/Unit/Services/FastmailConnectorTest.php
git commit -m "fix: request Fastmail email body values"
```

---

### Task 1.2: Normalize text from `bodyValues` for text and HTML-only emails

**Files:**
- Modify: `tests/Unit/Services/FastmailConnectorTest.php`
- Modify: `app/Services/Fastmail/FastmailConnector.php`

- [ ] **Step 1: Add failing normalization tests**

Add tests covering:

- text body assembled from `textBody[*].partId` -> `bodyValues[partId].value`
- multiple `textBody` parts are concatenated in order
- HTML-only body assembled from `htmlBody[*].partId` -> `bodyValues[partId].value`
- missing `bodyValues` does not crash and yields empty body text

Example test body:

```php
$this->assertSame('Body text', $result['messages'][0]->bodyText);
```

and for HTML-only:

```php
$this->assertSame("Hello\nWorld", $result['messages'][0]->bodyText);
```

- [ ] **Step 2: Run the focused connector tests and verify failure**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: FAIL because `normalizeMessages()` still reads `textBody[0].value`.

- [ ] **Step 3: Implement minimal normalization helpers**

In `FastmailConnector`, add focused private helpers to:

- collect matching `bodyValues` for `textBody`
- fall back to `htmlBody` when no usable text body exists
- convert HTML to text conservatively before assigning `NormalizedEmailMessage::$bodyText`

Do not change unrelated connector behavior.

- [ ] **Step 4: Re-run the focused connector tests**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Fastmail/FastmailConnector.php tests/Unit/Services/FastmailConnectorTest.php
git commit -m "fix: normalize Fastmail body text from body values"
```

---

### Task 1.3: Add single-message fetch by provider message id

**Files:**
- Modify: `tests/Unit/Services/FastmailConnectorTest.php`
- Modify: `app/Services/Fastmail/FastmailConnector.php`

- [ ] **Step 1: Add a failing single-message fetch test**

Add a test for a method like:

```php
$message = $connector->fetchMessageById($account, 'msg-1');
```

Assert:

- it returns `NormalizedEmailMessage` when Fastmail returns the message
- it returns `null` when Fastmail returns no matching records

- [ ] **Step 2: Run the focused connector tests and verify failure**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: FAIL because the method does not exist.

- [ ] **Step 3: Implement `fetchMessageById()`**

Use the same body-fetch request options and the same normalization path as batch fetches. Scope all requests through the passed `MailAccount`.

- [ ] **Step 4: Re-run the focused connector tests**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Fastmail/FastmailConnector.php tests/Unit/Services/FastmailConnectorTest.php
git commit -m "feat: add Fastmail single message fetch"
```

---

## Chunk 2: Add targeted per-row repair behavior

### Task 2.1: Implement the repair service for one `ImportedEmail`

**Files:**
- Create: `app/Services/Email/ImportedEmailBodyRepairService.php`
- Create: `tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php`
- Reference: `app/Services/Email/EmailBodyCleanupService.php`
- Reference: `app/Models/ImportedEmail.php`

- [ ] **Step 1: Write the failing repair-service tests**

Create `tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php` covering:

- repairs a Fastmail row with `body_text = null`
- repairs a Fastmail row with `body_text = ''`
- skips rows with `processing_status = filtered`
- skips rows with `rule_action = ignore`
- skips rows whose `mail_account_id` no longer resolves to an existing account
- skips rows when `fetchMessageById()` returns `null`
- skips rows when cleanup still produces an empty body
- preserves `thought_id`, `thought_deleted_at`, `review_inbox_item_id`, `research_thought_id`, `processing_status`, `rule_action`, `rule_email`, `failure_count`, `failure_reason`, and `summary`
- does not create a second `ImportedEmail` row or change the existing row id
- is safe to rerun: a second repair pass leaves the same row in place without duplicating state
- updates `content_fingerprint` using the same formula as import

Example assertion:

```php
$this->assertSame(
    hash('sha256', implode('|', ['msg-1', 'Subject', 'Clean body'])),
    $row->fresh()->content_fingerprint
);
```

- [ ] **Step 2: Run the focused repair-service tests and verify failure**

Run:

```bash
php artisan test tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php
```

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement `ImportedEmailBodyRepairService`**

Add a focused method such as:

```php
public function repair(ImportedEmail $row, bool $dryRun = false): array
```

Return a result payload with a machine-friendly status, for example:

```php
[
    'status' => 'repaired', // or skipped_missing_message / skipped_filtered / skipped_empty_body
    'updated' => true,
]
```

Use:

- `FastmailConnector::fetchMessageById()`
- `EmailBodyCleanupService::clean()`

Only update:

- `body_text`
- `content_fingerprint`
- optional `processing_metadata_json['body_repair']`, merged into any existing metadata without replacing other keys

- [ ] **Step 4: Re-run the focused repair-service tests**

Run:

```bash
php artisan test tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Email/ImportedEmailBodyRepairService.php tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php
git commit -m "feat: add imported email body repair service"
```

---

## Chunk 3: Add an operator-safe repair command

### Task 3.1: Add the console command with dry-run, limit, and account scope

**Files:**
- Create: `app/Console/Commands/RepairImportedEmailBodiesCommand.php`
- Create: `tests/Feature/RepairImportedEmailBodiesCommandTest.php`
- Modify: `routes/console.php` or the app’s console command registration point if needed
- Reference: `app/Services/Email/ImportedEmailBodyRepairService.php`

- [ ] **Step 1: Write the failing command tests**

Create `tests/Feature/RepairImportedEmailBodiesCommandTest.php` covering:

- dry-run reports eligible rows but does not update them
- `--limit=1` repairs only one row
- `--mail-account-id=` scopes work to one account
- command skips filtered rows
- command skips ignored rows
- command is safe to rerun without duplicating updates or changing counts unexpectedly
- command reports repaired / skipped / failed counts

Example command call:

```php
$this->artisan('emails:repair-imported-bodies', ['--dry-run' => true])
    ->expectsOutputToContain('Eligible')
    ->assertExitCode(0);
```

- [ ] **Step 2: Run the focused command tests and verify failure**

Run:

```bash
php artisan test tests/Feature/RepairImportedEmailBodiesCommandTest.php
```

Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command**

Add a command signature like:

```php
emails:repair-imported-bodies {--dry-run} {--limit=100} {--mail-account-id=} 
```

Implementation rules:

- query only `fastmail` rows
- require `body_text` null or trim-empty
- require `processing_status != filtered`
- skip `rule_action = ignore`
- require an existing associated `mail_account`
- process in small batches with conservative pacing, for example one batch per chunked query plus a short sleep between batches
- isolate failures per row with try/catch so one bad repair result is counted as failed and the command continues
- delegate each row to `ImportedEmailBodyRepairService`

- [ ] **Step 4: Register the command if needed**

Wire the command into the app’s console command registration path used by this codebase.

- [ ] **Step 5: Re-run the focused command tests**

Run:

```bash
php artisan test tests/Feature/RepairImportedEmailBodiesCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/RepairImportedEmailBodiesCommand.php tests/Feature/RepairImportedEmailBodiesCommandTest.php routes/console.php
git commit -m "feat: add imported email body repair command"
```

---

## Chunk 4: Verify integration boundaries and regressions

### Task 4.1: Confirm normal import behavior still matches existing expectations

**Files:**
- Verify only
- Reference: `tests/Unit/Services/EmailImportServiceTest.php`
- Reference: `tests/Feature/BackfillMailAccountJobTest.php`

- [ ] **Step 1: Run the existing import-service tests**

Run:

```bash
php artisan test tests/Unit/Services/EmailImportServiceTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the existing Fastmail job tests**

Run:

```bash
php artisan test tests/Feature/BackfillMailAccountJobTest.php
```

Expected: PASS.

- [ ] **Step 3: If fingerprint logic was duplicated, refactor only after green**

If both import and repair now calculate the same fingerprint inline, extract a tiny shared helper only if duplication is clearly harmful. If you refactor, add a focused regression test first.

- [ ] **Step 4: Commit if refactor was needed**

```bash
git add app/Services/Email app/Services/Fastmail tests/Unit/Services tests/Feature
git commit -m "refactor: share imported email fingerprint logic"
```

Skip this commit if no refactor was needed.

---

### Task 4.2: Run final verification and document operator usage

**Files:**
- Modify: `docs/superpowers/plans/2026-03-24-imported-email-body-repair.md`

- [ ] **Step 1: Run the focused full test set**

Run:

```bash
php artisan test tests/Unit/Services/FastmailConnectorTest.php
php artisan test tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php
php artisan test tests/Feature/RepairImportedEmailBodiesCommandTest.php
php artisan test tests/Unit/Services/EmailImportServiceTest.php
php artisan test tests/Feature/BackfillMailAccountJobTest.php
```

Expected: PASS.

- [ ] **Step 2: Run a manual dry-run locally**

Run:

```bash
php artisan emails:repair-imported-bodies --dry-run --limit=20
```

Expected:

- command exits `0`
- output clearly reports eligible / repaired / skipped / failed counts
- no rows are modified in dry-run mode

- [ ] **Step 3: Record the operator command examples in this plan**

Append a short “Operator notes” section with:

- dry-run example
- account-scoped example
- real execution example

- [ ] **Step 4: Final commit**

```bash
git add app/Services/Fastmail/FastmailConnector.php app/Services/Email/ImportedEmailBodyRepairService.php app/Console/Commands/RepairImportedEmailBodiesCommand.php tests/Unit/Services/FastmailConnectorTest.php tests/Unit/Services/ImportedEmailBodyRepairServiceTest.php tests/Feature/RepairImportedEmailBodiesCommandTest.php docs/superpowers/plans/2026-03-24-imported-email-body-repair.md
git commit -m "fix: repair missing imported email body text"
```

---

## Execution handoff

### Operator notes

Dry run:

```bash
php artisan emails:repair-imported-bodies --dry-run --limit=20
```

Account-scoped dry run:

```bash
php artisan emails:repair-imported-bodies --dry-run --mail-account-id=123 --limit=20
```

Real execution:

```bash
php artisan emails:repair-imported-bodies --mail-account-id=123 --limit=50
```

Recommended first operator run after implementation:

```bash
php artisan emails:repair-imported-bodies --dry-run --limit=50
php artisan emails:repair-imported-bodies --mail-account-id=123 --limit=50
```

Because this touches an active ingestion area, execute in an isolated worktree before coding.

Plan complete and saved to `docs/superpowers/plans/2026-03-24-imported-email-body-repair.md`.

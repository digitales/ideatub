# Newsletter Link Summary Backfill Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Artisan command that can backfill editorial link summaries onto existing newsletter research thoughts in place, with an optional `--requeue` mode that performs the destructive newsletter reset flow first.

**Architecture:** Reuse the existing newsletter research pipeline instead of introducing a second orchestration path. Add a new backfill command that scans stored email rows and dispatches `ProcessExtraEmailResearch`, plus a small shared reset service so the command and `EmailResearchController::newsletterResearch()` use the same destructive requeue behavior.

**Tech Stack:** Laravel 12, PHP 8.2+, Artisan console commands, Laravel queues, PHPUnit feature tests, Eloquent

---

## File Structure

**Create:**

- `app/Console/Commands/BackfillNewsletterLinkSummariesCommand.php` — scans eligible stored email rows, reports counts, supports default in-place backfill plus `--requeue`.
- `app/Services/Email/ResetNewsletterResearchState.php` — shared reset helper for destructive newsletter requeue behavior across controller and command.
- `tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php` — feature coverage for in-place mode, filters, dry run, missing research thoughts, and `--requeue`.

**Modify:**

- `app/Http/Controllers/EmailResearchController.php` — replace inline newsletter reset logic with the shared reset helper.
- `tests/Feature/EmailResearchControllerTest.php` — keep existing controller behavior green after the refactor to shared reset logic.

## Task 1: Add Feature Coverage For The New Backfill Command

**Files:**

- Create: `tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

- [ ] **Step 1: Write the failing default-mode backfill test**

```php
#[Test]
public function default_mode_dispatches_process_extra_email_research_for_imported_rows_with_existing_research(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $account = MailAccount::factory()->create(['user_id' => $user->id]);
    $emailThought = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'email',
    ]);
    $research = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'research',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    $row = ImportedEmail::query()->create([
        'user_id' => $user->id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'backfill-summary-1',
        'direction' => 'received',
        'subject' => 'Digest',
        'body_text' => 'Newsletter body',
        'processing_status' => 'research_completed',
        'thought_id' => $emailThought->id,
        'research_thought_id' => $research->id,
    ]);

    $this->artisan('email-research:backfill-link-summaries')
        ->assertSuccessful()
        ->expectsOutputToContain('Scanned: 1')
        ->expectsOutputToContain('Queued: 1')
        ->expectsOutputToContain('Requeued: 0');

    Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row) {
        return $job->importedEmailId === $row->id;
    });
}
```

- [ ] **Step 2: Add the other failing command tests in the same file**

Cover these scenarios:

- captured inbound rows dispatch in default mode
- default mode `--dry-run` reports `Queued` but dispatches nothing
- broken `research_thought_id` increments `Missing research thought`
- `--user-id`, `--limit`, and `--stored-type` narrow the scan correctly
- `--requeue` clears the stored `research_thought_id`, deletes old `ThoughtLinkSummary` rows, clears `newsletter_research` metadata, and dispatches `ProcessExtraEmailResearch`
- `--requeue` still resets queue state and dispatches when `research_thought_id` was already null, while skipping `ThoughtLinkSummary` deletion
- `--dry-run --requeue` reports `Requeued` but does not mutate data
- unrelated `ThoughtLinkSummary` rows survive requeue

- [ ] **Step 3: Run the new command test file to verify it fails**

Run: `php artisan test tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

Expected: FAIL because the command and shared reset helper do not exist yet.

## Task 2: Extract Shared Newsletter Requeue Reset Logic

**Files:**

- Create: `app/Services/Email/ResetNewsletterResearchState.php`
- Modify: `app/Http/Controllers/EmailResearchController.php`
- Modify: `tests/Feature/EmailResearchControllerTest.php`

- [ ] **Step 1: Capture the current controller behavior with one focused regression assertion**

Add or tighten a controller test so it still proves:

- stale `ThoughtLinkSummary` rows are deleted for the previous research thought
- `newsletter_research` metadata is cleared
- the stored row becomes `research_queued`

- [ ] **Step 2: Run the targeted controller test to confirm the baseline**

Run: `php artisan test tests/Feature/EmailResearchControllerTest.php --filter=newsletter_research`

Expected: PASS before the refactor, so the current behavior is captured before extracting the helper.

- [ ] **Step 3: Implement the shared reset helper**

Create `ResetNewsletterResearchState` with a method shaped like:

```php
public function reset(Thought $thought, ImportedEmail|CapturedInboundEmail $stored): void
{
    DB::transaction(function () use ($thought, $stored): void {
        $previousResearchThoughtId = $stored->research_thought_id;

        $stored->processing_status = 'research_queued';
        $stored->research_thought_id = null;
        $stored->save();

        if ($previousResearchThoughtId !== null) {
            ThoughtLinkSummary::query()
                ->where('source_thought_id', $thought->id)
                ->where('parent_research_thought_id', $previousResearchThoughtId)
                ->delete();
        }

        $meta = $thought->source_metadata ?? [];
        unset($meta['newsletter_research']);
        $thought->source_metadata = $meta;
        $thought->save();
    });
}
```

- [ ] **Step 4: Update `EmailResearchController::newsletterResearch()` to use the helper**

Replace the inline reset transaction with the new service, then keep the existing post-commit dispatch exactly as it is today.

- [ ] **Step 5: Re-run the controller test**

Run: `php artisan test tests/Feature/EmailResearchControllerTest.php --filter=newsletter_research`

Expected: PASS.

## Task 3: Implement The Backfill Command Default Mode

**Files:**

- Create: `app/Console/Commands/BackfillNewsletterLinkSummariesCommand.php`
- Test: `tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

- [ ] **Step 1: Implement the command signature and counters**

Use this signature:

```php
protected $signature = 'email-research:backfill-link-summaries
    {--dry-run : Show counts and actions without dispatching jobs}
    {--requeue : Clear existing newsletter linkage state before dispatching}
    {--user-id= : Limit scanning to one user}
    {--limit= : Stop after N scanned rows}
    {--stored-type= : Limit to imported or captured rows}';
```

Track counters:

- `$scanned`
- `$queued`
- `$requeued`
- `$skipped`
- `$missingResearchThought`

- [ ] **Step 2: Implement default-mode scanning for imported and captured rows**

Rules:

- process `ImportedEmail` rows first, then `CapturedInboundEmail` rows, matching `BackfillEmailResearchLinksCommand`, so `--limit` behaves deterministically
- scan rows with `thought_id` not null
- in default mode, require `research_thought_id` not null
- apply `--user-id`, `--limit`, and `--stored-type` filters
- resolve the linked thought using the same canonical email-thought eligibility as `BackfillEmailResearchLinksCommand` (`matchingCanonicalSourceType('email')` plus same `user_id`)
- if `research_thought_id` is null in default mode, increment `Skipped` and do not dispatch
- resolve the research thought using the same practical eligibility as `BackfillEmailResearchLinksCommand` for default mode counting: same `user_id` plus a real research thought record, not just any thought with a matching id
- if `research_thought_id` is present but no longer resolves to that eligible research thought in default mode, increment `Missing research thought`
- otherwise increment `Queued` and dispatch `ProcessExtraEmailResearch` unless `--dry-run`

- [ ] **Step 3: Print the command summary**

Print:

```php
$this->line('Scanned: '.$this->scanned);
$this->line('Queued: '.$this->queued);
$this->line('Requeued: '.$this->requeued);
$this->line('Skipped: '.$this->skipped);
$this->line('Missing research thought: '.$this->missingResearchThought);
```

And when `--dry-run`:

```php
$this->comment('Dry run: no database writes or job dispatches were performed.');
```

- [ ] **Step 4: Re-run the new command test file**

Run: `php artisan test tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

Expected: still FAIL on the `--requeue` cases, but the default-mode tests should now pass.

## Task 4: Add `--requeue` Mode To The Command

**Files:**

- Modify: `app/Console/Commands/BackfillNewsletterLinkSummariesCommand.php`
- Test: `tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

- [ ] **Step 1: Implement destructive requeue mode using the shared reset helper**

When `--requeue` is present:

- resolve the linked email thought
- do not require `research_thought_id` to be present for eligibility
- call `ResetNewsletterResearchState::reset($thought, $stored)`
- increment `Requeued`
- dispatch `ProcessExtraEmailResearch` after the reset unless `--dry-run`

Notes:

- if the row had no prior `research_thought_id`, the helper should still reset `processing_status`, null the stored linkage, and clear stale `newsletter_research` metadata, but it should skip `ThoughtLinkSummary` deletion
- dispatch `ProcessExtraEmailResearch` only after the reset transaction has committed; do not wrap reset + dispatch inside one larger transaction
- `Requeued` and `Queued` should stay mutually exclusive per row
- `Missing research thought` is a default-mode-only counter; `--requeue` should never increment it, even when an old `research_thought_id` was stale

- [ ] **Step 2: Keep counter semantics mutually exclusive**

For any single row, it should increment exactly one of:

- `Queued`
- `Requeued`
- `Skipped`
- `Missing research thought`

- [ ] **Step 3: Re-run the full new command test file**

Run: `php artisan test tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`

Expected: PASS.

## Task 5: Final Verification

**Files:**

- Modify: none expected
- Test: `tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php`
- Test: `tests/Feature/EmailResearchControllerTest.php`
- Test: `tests/Feature/ProcessExtraEmailResearchJobTest.php`

- [ ] **Step 1: Run the focused verification suite**

Run: `php artisan test tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php tests/Feature/EmailResearchControllerTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php`

Expected: PASS.

- [ ] **Step 2: Run one broader newsletter/research regression pass**

Run: `php artisan test --filter=Research`

Expected: PASS.

- [ ] **Step 3: Run formatting and lint checks on touched files**

Run:

```bash
./vendor/bin/pint --test app/Console/Commands/BackfillNewsletterLinkSummariesCommand.php app/Services/Email/ResetNewsletterResearchState.php app/Http/Controllers/EmailResearchController.php tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php tests/Feature/EmailResearchControllerTest.php
```

Then run `ReadLints` on the same files.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/BackfillNewsletterLinkSummariesCommand.php app/Services/Email/ResetNewsletterResearchState.php app/Http/Controllers/EmailResearchController.php tests/Feature/BackfillNewsletterLinkSummariesCommandTest.php tests/Feature/EmailResearchControllerTest.php
git commit -m "feat: backfill newsletter link summaries"
```

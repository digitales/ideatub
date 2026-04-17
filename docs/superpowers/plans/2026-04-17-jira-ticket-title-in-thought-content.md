# Jira Ticket Title in Thought Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure Jira thoughts always include both issue key and issue title in content for all event types, and backfill existing Jira thoughts to the same format.

**Architecture:** Keep content construction centralized in `JiraSyncService` so all newly synced events use one formatter contract (`KEY: Summary - detail`). Add a dedicated backfill command that rewrites legacy Jira thought content in chunks using existing `source_metadata`, with dry-run support and safe skip/fallback behavior. Preserve existing metadata and sync idempotency behavior.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit/Pest test runner, Laravel HTTP fakes, Artisan commands, Eloquent chunking.

**Spec:** `docs/superpowers/specs/2026-04-17-jira-ticket-title-in-thought-content-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `app/Services/JiraSyncService.php` | Add canonical formatter and apply it to created/updated/comment event content |
| `tests/Unit/JiraSyncServiceTest.php` | Verify all event content includes `jira_issue_key` + `jira_issue_summary` and preserves event detail |
| `app/Console/Commands/BackfillJiraThoughtContentCommand.php` | Backfill existing Jira thought content with key + title prefix and dry-run support |
| `tests/Feature/JiraBackfillThoughtContentCommandTest.php` | Verify command updates eligible rows, skips malformed rows, and dry-run does not write |

---

### Task 1: Normalize Jira event content in sync service

**Files:**
- Modify: `tests/Unit/JiraSyncServiceTest.php`
- Modify: `app/Services/JiraSyncService.php`

- [ ] **Step 1: Write the failing unit assertions for content contract**

Add/update assertions in `fetch_events_returns_events_with_required_shape_when_http_mocked` and `fetch_events_extracts_full_text_from_nested_adf_comments`:

```php
$this->assertSame('PROJ-1', $event['source_metadata']['jira_issue_key'] ?? null);
$summary = (string) ($event['source_metadata']['jira_issue_summary'] ?? '');
$this->assertNotSame('', trim($summary));
$expectedPrefix = "PROJ-1: {$summary}";
$this->assertStringStartsWith($expectedPrefix, $event['content']);

$eventType = $event['source_metadata']['jira_event_type'] ?? null;
if ($eventType === 'created') {
    $this->assertStringContainsString(' - Created', $event['content']);
}
if ($eventType === 'comment') {
    $this->assertStringContainsString(' - Commented: ', $event['content']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/JiraSyncServiceTest.php --filter=fetch_events`
Expected: FAIL because current content starts with `Created PROJ-1` or `Commented on PROJ-1` and does not follow `KEY: Summary - ...`.

- [ ] **Step 3: Implement minimal formatter in JiraSyncService**

In `app/Services/JiraSyncService.php`, add a helper and switch each event to pass detail text instead of hardcoded full strings:

```php
private function formatIssueEventContent(string $issueKey, string $issueSummary, string $detail): string
{
    $key = trim($issueKey);
    $summary = trim($issueSummary);
    $detail = trim($detail);

    $prefix = $summary !== '' ? "{$key}: {$summary}" : $key;

    return $detail !== '' ? "{$prefix} - {$detail}" : $prefix;
}
```

Use it in each event builder call:

```php
$createdContent = $this->formatIssueEventContent($key, $summary, 'Created');
$updatedContent = $this->formatIssueEventContent($key, $summary, implode('; ', $descriptions));
$commentContent = $this->formatIssueEventContent($key, $summary, "Commented: {$body}");
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/JiraSyncServiceTest.php --filter=fetch_events`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/JiraSyncService.php tests/Unit/JiraSyncServiceTest.php
git commit -m "feat(jira): include issue title in synced event content"
```

---

### Task 2: Add backfill command for existing Jira thoughts

**Files:**
- Create: `app/Console/Commands/BackfillJiraThoughtContentCommand.php`
- Create: `tests/Feature/JiraBackfillThoughtContentCommandTest.php`

- [ ] **Step 1: Write failing feature tests for update/skip/dry-run**

Create `tests/Feature/JiraBackfillThoughtContentCommandTest.php` with three tests:

```php
public function test_backfill_updates_legacy_jira_content_with_key_and_summary_prefix(): void
{
    $thought = Thought::factory()->create([
        'source' => 'jira',
        'content' => 'Commented on PROJ-1: First line',
        'source_metadata' => [
            'jira_issue_key' => 'PROJ-1',
            'jira_issue_summary' => 'Payments bug',
            'jira_event_type' => 'comment',
        ],
    ]);

    $this->artisan('jira:backfill-thought-content')
        ->assertSuccessful()
        ->expectsOutputToContain('Updated 1 thought(s)');

    $thought->refresh();
    $this->assertSame('PROJ-1: Payments bug - Commented: First line', $thought->content);
}
```

```php
public function test_backfill_skips_rows_missing_key_or_summary(): void
{
    $thought = Thought::factory()->create([
        'source' => 'jira',
        'content' => 'Created PROJ-2',
        'source_metadata' => ['jira_issue_key' => 'PROJ-2'],
    ]);

    $this->artisan('jira:backfill-thought-content')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped 1 thought(s)');

    $this->assertSame('Created PROJ-2', $thought->fresh()->content);
}
```

```php
public function test_backfill_dry_run_reports_without_writing(): void
{
    $thought = Thought::factory()->create([
        'source' => 'jira',
        'content' => 'Created PROJ-3',
        'source_metadata' => [
            'jira_issue_key' => 'PROJ-3',
            'jira_issue_summary' => 'Onboarding polish',
            'jira_event_type' => 'created',
        ],
    ]);

    $this->artisan('jira:backfill-thought-content', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run complete');

    $this->assertSame('Created PROJ-3', $thought->fresh()->content);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/JiraBackfillThoughtContentCommandTest.php`
Expected: FAIL (command class/signature missing).

- [ ] **Step 3: Implement command with chunked rewrite and safe parsing**

Create `app/Console/Commands/BackfillJiraThoughtContentCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;

class BackfillJiraThoughtContentCommand extends Command
{
    protected $signature = 'jira:backfill-thought-content {--dry-run : Show updates without writing}';
    protected $description = 'Normalize Jira thought content to include issue key and issue summary.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        Thought::query()
            ->where('source', 'jira')
            ->orderBy('id')
            ->chunkById(200, function ($thoughts) use (&$updated, &$skipped, $dryRun): void {
                foreach ($thoughts as $thought) {
                    $meta = is_array($thought->source_metadata) ? $thought->source_metadata : [];
                    $key = trim((string) ($meta['jira_issue_key'] ?? ''));
                    $summary = trim((string) ($meta['jira_issue_summary'] ?? ''));
                    $eventType = trim((string) ($meta['jira_event_type'] ?? ''));

                    if ($key === '' || $summary === '') {
                        $skipped++;
                        continue;
                    }

                    $detail = $this->extractLegacyDetail($thought->content, $key, $summary, $eventType);
                    $newContent = "{$key}: {$summary}" . ($detail !== '' ? " - {$detail}" : '');
                    if ($newContent === $thought->content) {
                        continue;
                    }

                    $updated++;
                    if (! $dryRun) {
                        $thought->update(['content' => $newContent]);
                    }
                }
            });

        if ($dryRun) {
            $this->info("Dry run complete. Would update {$updated} thought(s). Skipped {$skipped} thought(s).");
            return self::SUCCESS;
        }

        $this->info("Updated {$updated} thought(s). Skipped {$skipped} thought(s).");
        return self::SUCCESS;
    }
}
```

Add helper in the command for deterministic detail extraction:

```php
private function extractLegacyDetail(string $content, string $key, string $summary, string $eventType): string
{
    $value = trim($content);
    $value = preg_replace('/^\Q' . preg_quote($key, '/') . '\E:\s*' . preg_quote($summary, '/') . '\s*-\s*/', '', $value) ?? $value;
    $value = preg_replace('/^Created\s+\Q' . preg_quote($key, '/') . '\E:?\s*/', '', $value) ?? $value;
    $value = preg_replace('/^Commented on\s+\Q' . preg_quote($key, '/') . '\E:\s*/', 'Commented: ', $value) ?? $value;
    $value = preg_replace('/^\Q' . preg_quote($key, '/') . '\E:\s*/', '', $value) ?? $value;

    if ($eventType === 'created' && $value === '') {
        return 'Created';
    }

    return trim($value);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/JiraBackfillThoughtContentCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackfillJiraThoughtContentCommand.php tests/Feature/JiraBackfillThoughtContentCommandTest.php
git commit -m "feat(jira): add backfill command for key and title thought content"
```

---

### Task 3: Harden edge cases and validate no regressions

**Files:**
- Modify: `tests/Unit/JiraSyncServiceTest.php`
- Modify: `tests/Feature/JiraBackfillThoughtContentCommandTest.php`
- Modify: `app/Console/Commands/BackfillJiraThoughtContentCommand.php` (if needed after test failures)

- [ ] **Step 1: Add focused edge-case tests**

Add these additional tests:

```php
public function test_fetch_events_uses_issue_key_when_summary_is_blank(): void
{
    Http::fake([
        '*rest/api/3/myself' => Http::response(['accountId' => 'acc-123'], 200),
        '*rest/api/3/search*' => Http::response([
            'issues' => [[
                'key' => 'PROJ-1',
                'id' => '10001',
                'fields' => [
                    'summary' => '   ',
                    'project' => ['key' => 'PROJ'],
                    'created' => '2026-01-01T10:00:00.000+0000',
                    'updated' => '2026-01-01T10:00:00.000+0000',
                ],
            ]],
        ], 200),
        '*rest/api/3/issue/PROJ-1/comment*' => Http::response(['comments' => []], 200),
        '*rest/api/3/issue/PROJ-1*' => Http::response(['changelog' => ['histories' => []]], 200),
    ]);

    $user = User::factory()->create();
    UserJiraCredential::create([
        'user_id' => $user->id,
        'jira_site_url' => 'https://example.atlassian.net',
        'jira_api_token' => 'secret-token',
    ]);

    $events = (new JiraSyncService)->fetchEvents($user, 14);
    $createdEvent = collect($events)->firstWhere('source_metadata.jira_event_type', 'created');

    $this->assertNotNull($createdEvent);
    $this->assertSame('PROJ-1: Created', $createdEvent['content']);
}
```

```php
public function test_backfill_preserves_existing_content_when_detail_extraction_is_ambiguous(): void
{
    $thought = Thought::factory()->create([
        'source' => 'jira',
        'content' => 'custom legacy format: moved to qa by release manager',
        'source_metadata' => [
            'jira_issue_key' => 'PROJ-88',
            'jira_issue_summary' => 'Release flow cleanup',
            'jira_event_type' => 'updated',
        ],
    ]);

    $this->artisan('jira:backfill-thought-content')
        ->assertSuccessful();

    $thought->refresh();
    $this->assertSame(
        'PROJ-88: Release flow cleanup - custom legacy format: moved to qa by release manager',
        $thought->content
    );
}
```

- [ ] **Step 2: Run targeted tests**

Run: `php artisan test tests/Unit/JiraSyncServiceTest.php tests/Feature/JiraBackfillThoughtContentCommandTest.php`
Expected: PASS.

- [ ] **Step 3: Run broader Jira regression tests**

Run: `php artisan test tests/Feature/JiraSyncJobTest.php tests/Feature/JiraBackfillCommentsCommandTest.php`
Expected: PASS, confirming no sync idempotency regression and existing comment backfill still works.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/JiraSyncServiceTest.php tests/Feature/JiraBackfillThoughtContentCommandTest.php app/Console/Commands/BackfillJiraThoughtContentCommand.php
git commit -m "test(jira): cover formatter and backfill edge cases"
```

---

## Self-review checklist

- Spec coverage: all approved requirements are mapped to Tasks 1-3 (new sync formatting, backfill command, safety behavior, and tests).
- Placeholder scan: no TODO/TBD placeholders remain in executable steps.
- Type consistency: command signature, metadata keys (`jira_issue_key`, `jira_issue_summary`, `jira_event_type`), and formatter output contract are consistent across tasks.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-17-jira-ticket-title-in-thought-content.md`.

Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration
2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?

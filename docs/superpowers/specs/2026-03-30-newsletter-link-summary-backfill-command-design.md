# Newsletter Link Summary Backfill Command Design

## Goal

Add a console command that can update older newsletter research with editorial link summaries after the new link-summary pipeline has shipped.

The command should support two modes:

- default in-place backfill, which preserves the existing linked research thought and only queues missing `ThoughtLinkSummary` work against it
- optional full requeue mode, which clears prior newsletter research linkage/summary state first and then re-dispatches the existing newsletter research job flow

## Why

Older newsletter research thoughts predate the editorial link summary pipeline. Users need a way to populate link summaries onto those existing research thoughts without manually re-running every newsletter one by one. Some cases also need a heavier rebuild path when the original research should be refreshed from scratch.

## Existing Building Blocks

The command should reuse existing behavior instead of introducing a parallel processing flow:

- `ProcessExtraEmailResearch` already supports in-place backfill when a stored email still has a valid `research_thought_id`; it detects the existing research thought and queues editorial link summaries onto it
- `EmailResearchController::newsletterResearch()` already defines the destructive requeue/reset behavior for a single email thought
- `BackfillEmailResearchLinksCommand` already establishes the preferred command style for dry-run counters and scanning imported plus captured email rows

## Command Shape

Add a new Artisan command:

`email-research:backfill-link-summaries`

### Options

- `--dry-run`
  - report counts and intended actions without writing changes or dispatching jobs
- `--requeue`
  - use the destructive rebuild path instead of the default in-place backfill path
- `--user-id=`
  - limit scanning to one owner
- `--limit=`
  - stop after a bounded number of eligible rows
- `--stored-type=imported|captured`
  - optionally limit scanning to one stored-email table

## Scan Criteria

The command should scan stored email rows using the same practical linkage rules already implied by `ProcessExtraEmailResearch` and `BackfillEmailResearchLinksCommand`, rather than inventing a stricter `rule_action` requirement.

Base scan rules:

- `ImportedEmail` rows with `thought_id` not null
- `CapturedInboundEmail` rows with `thought_id` not null

Mode-specific eligibility:

- default in-place mode requires `research_thought_id` not null because it attaches summaries to an already-linked research thought
- `--requeue` mode may operate on rows with a linked email thought regardless of whether `research_thought_id` is currently present, because that mode intentionally rebuilds queue state

Rows should be skipped when:

- the linked thought is missing
- the linked thought is not an email thought
- ownership is inconsistent
- the row is outside the selected filters

Default mode should separately track rows whose `research_thought_id` is present but no longer resolves to an eligible research thought.

## Default Mode: In-Place Backfill

Default behavior should be non-destructive.

For each eligible stored email row:

1. Resolve the linked email thought.
2. Resolve the existing research thought from `research_thought_id`.
3. If both exist, belong to the same user, and the linked thought is a canonical email thought, dispatch `ProcessExtraEmailResearch` for that stored email.

`ProcessExtraEmailResearch` already handles this case safely:

- it keeps the existing research thought
- it refreshes `newsletter_research` metadata on the email thought
- it calls `LinkSummaryDispatchService::queueNewsletterEditorialLinks(...)`
- that service upserts missing `ThoughtLinkSummary` rows and only dispatches `ProcessThoughtLinkSummary` for rows that still need work

This should be the command default because it is safe for bulk usage and does not destroy existing research content or replace research-thought linkage.

Rows that have an email thought but no valid current `research_thought_id` are not candidates for default-mode in-place attachment; they should be counted and skipped rather than implicitly rebuilt.

## Requeue Mode

When `--requeue` is present, the command should perform the same reset behavior used by the controller path before dispatching `ProcessExtraEmailResearch`.

For each eligible stored email row:

1. Resolve the linked email thought.
2. Capture the prior `research_thought_id`.
3. Inside a transaction:
   - set stored email `processing_status = research_queued`
   - set stored email `research_thought_id = null`
   - delete `ThoughtLinkSummary` rows where:
     - `source_thought_id = email thought id`
     - `parent_research_thought_id = previous research thought id`
   - remove stale `newsletter_research` metadata from the email thought
4. After commit, dispatch `ProcessExtraEmailResearch` for that stored email.

If there was no prior `research_thought_id`, the command should skip the `ThoughtLinkSummary` deletion step rather than performing any broader delete.

This mode is intentionally destructive to existing newsletter-research linkage state and should only run when the operator explicitly requests it.

## Dispatch Strategy

The command should dispatch existing jobs rather than performing fetch/summarize work inline.

Reasons:

- keeps behavior aligned with the normal application flow
- preserves queue retry/backoff semantics already implemented in `ProcessExtraEmailResearch` and `ProcessThoughtLinkSummary`
- avoids duplicating newsletter-research orchestration logic in a second code path
- makes bulk backfills resumable and operationally safer

## Output Counters

The command should report scan results in the same style as the existing backfill command.

Suggested counters:

- `Scanned`
- `Queued`
- `Requeued`
- `Skipped`
- `Missing research thought`

Rules:

- `Queued` counts default-mode in-place dispatches
- `Requeued` counts destructive reset+dispatch operations
- `Missing research thought` counts only default-mode rows whose `research_thought_id` was present but did not resolve to an eligible research thought
- `Skipped` counts other non-action outcomes such as filters, missing linked thought, non-email linked thought, ownership mismatch, or no valid work target
- `Missing research thought` and `Skipped` should be mutually exclusive for any single row

If `--dry-run` is set, print:

`Dry run: no database writes or job dispatches were performed.`

## Testing

Add feature coverage for:

### Default Mode

- imported email with existing research thought dispatches `ProcessExtraEmailResearch`
- captured inbound email with existing research thought dispatches `ProcessExtraEmailResearch`
- missing research thought increments `Missing research thought` and does not dispatch
- `--user-id`, `--limit`, and `--stored-type` filters narrow the scan correctly
- `--dry-run` reports intended queued count without dispatching jobs

### Requeue Mode

- requeue mode clears stored `research_thought_id`, deletes old `ThoughtLinkSummary` rows, clears stale `newsletter_research` metadata, and dispatches `ProcessExtraEmailResearch`
- unrelated `ThoughtLinkSummary` rows are preserved
- `--dry-run --requeue` reports intended resets without mutating data

## Safety Notes

- default mode should remain the command default because it is non-destructive
- requeue mode should never run implicitly
- the command should not delete or mutate the research thought record itself
- the command should rely on existing jobs for actual research/link-summary processing so operational retries stay centralized

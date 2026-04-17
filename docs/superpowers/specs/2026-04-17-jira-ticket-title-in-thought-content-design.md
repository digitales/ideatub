# Jira Ticket Title in Thought Content Design

## Goal

Improve Jira thought readability in IdeaTub by always storing both ticket reference and ticket title in thought content, so users can quickly understand context while browsing Stream and search results.

Target format:

- `PROJ-123: Ticket title - <event details>`

## Scope

### Included

- Standardize Jira sync content formatting across all event types:
  - created
  - updated/changelog
  - comment
- Keep existing Jira metadata contract unchanged.
- Backfill existing Jira thoughts so historical records follow the same display format.
- Add tests for formatter behavior and backfill behavior.

### Excluded

- New Jira API fields or schema changes.
- UI-specific rendering overrides for Jira thoughts.
- Re-embedding historical thoughts as part of this ticket.

## Architecture and Ownership

### JiraSyncService owns content construction

`JiraSyncService` remains the canonical boundary for building Jira thought content. A dedicated formatter method will produce a normalized prefix:

- `<issue key>: <issue summary>`

then append event detail text with a hyphen separator.

This keeps formatting logic centralized and consistent for all future syncs.

### Backfill via dedicated Artisan command

A one-time command updates existing `source=jira` thoughts in-place, using stored `source_metadata` (`jira_issue_key`, `jira_issue_summary`, `jira_event_type`) plus existing content detail.

Backfill logic stays separate from sync logic to keep regular job execution simple and performant.

## Content Contract

For each event type, content remains human-readable and starts with key + title:

- Created: `PROJ-123: Ticket title - Created`
- Updated: `PROJ-123: Ticket title - Status: To Do -> In Progress`
- Comment: `PROJ-123: Ticket title - Commented: <comment body>`

If event detail text already exists, it is preserved and only normalized around the new prefix.

## Data Flow

### New Sync Flow

1. Jira issue data and event details are collected as today.
2. Event detail text is generated per event type.
3. Formatter composes final `content` with key, summary, and detail.
4. Event is returned with unchanged metadata shape.
5. `SyncUserJiraActivity` persists thoughts with existing idempotency checks (`jira_event_id`).

### Backfill Flow

1. Command selects `Thought` rows where `source='jira'` in chunks.
2. For each row, read `jira_issue_key` and `jira_issue_summary` from `source_metadata`.
3. Extract or preserve event detail text from existing `content`.
4. Write normalized content using the same formatter contract.
5. Report totals: updated, skipped, failed.

## Error Handling and Safety

- Rows missing `jira_issue_key` or `jira_issue_summary` are skipped and counted.
- If event detail extraction is ambiguous, fallback to preserving existing event text after the new prefix.
- If transformation fails unexpectedly for a row, keep original content unchanged and log the thought id.
- Provide a `--dry-run` mode to preview impact before writing.

## Testing

### Unit

- Extend `JiraSyncServiceTest` to assert all event `content` strings include:
  - Jira issue key
  - Jira issue summary
  - event detail
- Add formatter edge-case coverage:
  - empty/whitespace summaries
  - multi-line comment detail
  - already-prefixed legacy content

### Feature

- Add command tests to verify:
  - backfill rewrites eligible Jira thoughts
  - malformed thoughts are skipped
  - dry-run performs no writes
  - summary output reports expected counts

## Rollout

1. Ship formatter update in Jira sync service.
2. Deploy command.
3. Run dry-run in production and review counts.
4. Run backfill command.
5. Spot-check Stream/search for legacy and newly synced Jira thoughts.

## Success Criteria

- New Jira thoughts always show both key and title in content.
- Historical Jira thoughts are normalized to the same format after backfill.
- No regressions in Jira sync idempotency or event capture coverage.

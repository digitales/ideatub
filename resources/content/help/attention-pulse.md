# Attention Pulse

Operator awareness dashboard: memory health, open commitments, and recent Jira activity in one place. Pulse complements Inbox triage and working-memory corpus sync — it answers *what needs attention today* without replacing Jira or becoming a task manager.

## Enable Pulse

```env
FEATURE_ATTENTION_PULSE=true
```

When the flag is off, `/pulse` returns 404 and the nav link is hidden. MCP `get_attention_overview` is not advertised.

Optional thresholds (`config/pulse.php`):

| Env | Default | Purpose |
|-----|---------|---------|
| `PULSE_MEMORY_STALE_DAYS` | 14 | Flag project scopes not refreshed recently |
| `PULSE_JIRA_DAYS` | 14 | Recent Jira section window |
| `PULSE_JIRA_FOLLOW_UP_DAYS` | 3 | Inbox Jira follow-up generator window |
| `PULSE_MEETING_ACTION_DAYS` | 30 | Meeting action items window |
| `PULSE_MAX_MEMORY_HEALTH` | 10 | Memory health row cap |
| `PULSE_MAX_COMMITMENTS` | 15 | Open commitments cap |
| `PULSE_MAX_JIRA` | 15 | Jira activity cap |

## Pulse page (`/pulse`)

Authenticated route behind `FEATURE_ATTENTION_PULSE`. Sections (empty sections omitted):

1. **Memory health** — fallback authoring, build in progress, stale freshness, old refresh, missing external sync on Elixirr client projects.
2. **Open commitments** — durable `commitment_items` when present; otherwise Next Actions / Open Questions from validated project memory and meeting compaction Action Items.
3. **Recent Jira** — deduped by issue key from Jira sync thoughts.

Each row links to the relevant memory route, compaction detail, Jira URL, or project memory. Commitment rows support **Done** and **Snooze** (same patterns as Inbox).

**Morning brief:** When Pulse count > 0, the home page shows a card linking to `/pulse`.

## Inbox generators (Phase 2)

When `FEATURE_ATTENTION_PULSE=true`, four generators run during scheduled inbox generation:

| Generator | Dedupe key prefix | Trigger |
|-----------|-------------------|---------|
| `WorkingMemoryFallbackGenerator` | `wm_fallback:{memory_id}` | Latest version `authoring_status = fallback` |
| `StaleProjectMemoryGenerator` | `stale_project_memory:{memory_id}` | Project scope stale/degraded or refresh older than `PULSE_MEMORY_STALE_DAYS` |
| `MeetingActionInboxGenerator` | `meeting_action:{version_id}:{hash}` | Recent meeting compaction Action Items |
| `JiraFollowUpInboxGenerator` | `jira_follow_up:{issue_key}:{updated_at}` | Jira `updated` / `comment` events in last 3 days |

**Cadence:** `inbox:generate` runs **hourly** (see `routes/console.php`). Generators respect `INBOX_MAX_NEW_PER_RUN` (default 5 new items per user per run) and skip duplicate pending dedupe keys.

Triage in **Stream → Inbox** (`/inbox`): done/snooze as usual.

## Commitments store (Phase 3)

`commitment_items` are populated automatically when Pulse is enabled:

- **Meeting compaction** (`SynthesizeMeetingCompactionJob`) — Action Items
- **Working memory build** (`WorkingMemoryBuilderService`) — Next Actions and Open Questions on validated/external versions
- **Jira sync** (`SyncUserJiraActivity`) — follow-ups on `updated` / `comment` events

Pulse reads open commitments first; legacy WM/meeting parsing is the fallback when the store is empty.

## MCP: `get_attention_overview`

When `FEATURE_ATTENTION_PULSE=true`, agents can fetch the same JSON shape as the Pulse page:

```json
{
  "jsonrpc": "2.0",
  "id": 20,
  "method": "get_attention_overview",
  "params": {}
}
```

**Response:**

```json
{
  "total_count": 2,
  "sections": [
    {
      "key": "memory_health",
      "title": "Memory health",
      "description": "...",
      "items": [
        {
          "kind": "memory_health",
          "severity": "high",
          "title": "Global",
          "subtitle": "Fallback authoring — ...",
          "href": "/memory",
          "meta": { "scope_type": "global", "scope_key": "global" },
          "source_ref": { "type": "working_memory", "id": "..." },
          "commitment_id": null
        }
      ]
    }
  ]
}
```

No parameters required; scoped to the authenticated MCP user.

## Operator checklist

1. Set `FEATURE_ATTENTION_PULSE=true`.
2. Connect Jira and run sync (`sync_jira` MCP or Settings).
3. Ensure meeting compactions exist for recent `capture_meeting` thoughts.
4. Visit `/pulse` after morning inbox generation.
5. For fallback memory inbox items → run `elixirr-sync` / `upsert_working_memory` or manual consolidate as appropriate.

## See also

- [Working memory corpus sync](/help/working-memory-corpus-sync) — capture meetings, bulk import, consolidation
- [MCP integration guide](/help) — `get_working_memory`, `upsert_working_memory`, `sync_jira`
- Design: `docs/superpowers/specs/2026-06-15-attention-pulse-design.md`
- Investigation context: `support/2026-06-15-global-working-memory-fallback-vs-insights-validated.md`

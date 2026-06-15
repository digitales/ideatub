# Attention Pulse — operator awareness dashboard

**Status:** Draft (awaiting review)  
**Date:** 2026-06-15  
**Owner:** Product + Engineering  

## Relationship to other specs

- Extends [`2026-05-08-working-memory-index-design.md`](2026-05-08-working-memory-index-design.md) (memory health signals).
- Extends [`2026-03-20-inbox-agent-design.md`](../../superpowers/specs/2026-03-20-inbox-agent-design.md) (Phase 2 generators).
- Complements [`2026-03-16-jira-integration-design.md`](../../superpowers/specs/2026-03-16-jira-integration-design.md) (Jira thought source).
- Aligns with [`2026-05-28-working-memory-sync-policy.md`](../plans/2026-05-28-working-memory-sync-policy.md) (curated sync, external-first projects).
- Investigation context: [`support/2026-06-15-global-working-memory-fallback-vs-insights-validated.md`](../../../support/2026-06-15-global-working-memory-fallback-vs-insights-validated.md).

## Problem

IdeaTub captures rich context (working memory, Jira, meetings, research) but spreads it across Memory, Stream, Inbox, and project pages. There is no **login-first overview** answering: *what needs my attention today so I can give the right answers in meetings and client calls?*

The user goal is **internal profile building** — staying reliably informed across clients, tickets, and commitments — not generic task management.

## Goal

Ship a three-phase **Attention Pulse** capability:

1. **Phase 1 — Pulse page:** Read-only aggregation dashboard at `/pulse`.
2. **Phase 2 — Inbox generators:** Actionable triage prompts for memory health, Jira follow-ups, and meeting actions.
3. **Phase 3 — Commitments store:** Durable open/closed commitment items with stable IDs, fed by compactions and Jira sync.

Each phase delivers standalone value. Later phases do not block earlier ones.

## Non-goals

- Replacing Jira or becoming a full task manager (assignees, sprints, burndown).
- Real-time push / WebSockets in v1.
- Multi-user team dashboards (single-user account scope only).
- LLM-generated Pulse copy in v1 (deterministic titles/subtitles from structured data).
- Discord/email delivery (future; Inbox already has optional delivery path).

## Resolved product decisions

| Topic | Decision |
|-------|----------|
| Route | `GET /pulse` (`pulse.show`), authenticated, behind `FEATURE_ATTENTION_PULSE` |
| Primary audience | Operator (Ross) — single account, multi-client |
| Layout | Sectioned cards (Memory health → Commitments → Jira → optional empty states), Memory UI styling |
| Morning brief | Summary card linking to Pulse when attention count > 0 |
| Nav | **Pulse** in primary nav when feature enabled (near Memory / Inbox) |
| Phase 2 delivery | New `InboxGenerator` classes; no Pulse-specific triage UI |
| Phase 3 model | New `commitment_items` table; distinct from `inbox_items` (durable vs prompt) |
| Consolidation window | Unrelated to Pulse thresholds; Pulse uses per-signal windows (see below) |

## Information architecture

```
Home (/) morning brief card ──► /pulse
Nav: Pulse ───────────────────► /pulse
/pulse sections:
  1. Memory health      → /memory, /memory/scopes, project memory
  2. Open commitments   → thought, memory section, Jira URL (phase 3: commitment detail)
  3. Recent Jira        → Stream Jira / external issue link
Inbox (phase 2)         → actionable prompts; done/snooze
```

## Phase 1 — Pulse page (read-only overview)

### Sections and rules

#### 1. Memory health

Surface scopes that may mislead agents or the operator.

| Signal | Rule | Severity |
|--------|------|----------|
| Fallback | `latestVersion.authoring_status === 'fallback'` | high |
| Updating | `build_started_at !== null` | medium |
| Stale freshness | `freshness_state` in `stale`, `degraded` | medium |
| Old refresh | `last_refreshed_at` older than **14 days** (configurable) | low |
| Missing external (project) | Project scope, no `external` version in **14 days**, and project is Elixirr client child or root | medium |

Reuse `WorkingMemoryScopeRowBadge`, `WorkingMemoryAssembler::forScope()` metadata, and project classification from `WorkingMemoryScopesIndexBuilder` (client grouping).

Limit: top **10** rows, sorted by severity then `last_refreshed_at` asc (oldest problems first).

#### 2. Open commitments (working memory)

From **canonical** working memory per active project scope (and optionally global):

- Extract bullets from `structured_sections['Next Actions']` and `structured_sections['Open Questions']` when `authoring_status` is `validated` or `external`.
- Include project/client label, scope link, citation links when present.
- Limit **5 per project**, **15 total**.

#### 3. Meeting action items

From `working_memory_versions` where `build_type = 'compaction:meeting'`, `created_at` within **30 days**:

- Parse `structured_sections_json['Action Items']` (array of `{text, citations}`).
- Link to compaction detail URL and source meeting thought when available in `references_json` / `working_memory_inputs`.
- Limit **20** items, newest meetings first.

#### 4. Recent Jira

From thoughts where canonical type is `jira`, `jira_updated_at` (or `created_at`) within **14 days** (config `pulse.jira_days`):

- Group by issue key; show latest event summary.
- Link: `source_metadata.jira_url` or Stream Jira card.
- Limit **15** issues.

### Empty states

- Section omitted when zero rows (no “empty” headings).
- Page-level empty: “Nothing needs attention — Pulse will surface memory issues, commitments, and Jira activity here.”

### API shape (internal DTO)

`AttentionOverviewData` with `sections: AttentionSectionData[]`, each holding `AttentionItemData[]`:

- `kind`: `memory_health` | `wm_commitment` | `meeting_action` | `jira_issue`
- `severity`: `high` | `medium` | `low` | null
- `title`, `subtitle`, `href`, `meta` (project name, scope, timestamps)
- `source_ref`: optional `{type, id}` for phase 3 linking

### Morning brief integration

When Pulse feature enabled and total attention items > 0:

- Card: kind `pulse`, title `N items need attention`, href `pulse.show`.

## Phase 2 — Inbox generators

Add generators to `config/inbox.php` (respect `max_new_items_per_user_per_run`):

| Generator | `generator_type` | Trigger | Dedupe key |
|-----------|------------------|---------|------------|
| `WorkingMemoryFallbackGenerator` | `memory_fallback` | Any scope with fallback canonical version | `memory_fallback:{scope_type}:{scope_key}` |
| `StaleProjectMemoryGenerator` | `memory_stale_project` | Project scope, no refresh in N days OR stale/degraded | `memory_stale:{scope_key}` |
| `MeetingActionInboxGenerator` | `meeting_action` | Unactioned meeting action items (phase 1 query; skip if matching pending inbox) | `meeting_action:{compaction_version_id}:{hash}` |
| `JiraFollowUpInboxGenerator` | `jira_follow_up` | Jira issue you touched, updated in last 3 days, status changed | `jira_follow_up:{issue_key}:{jira_updated_at}` |

**Body format:** Short markdown with link(s) and suggested action (“Run elixirr-sync”, “Open project memory”, “Reply on Jira”).

**Scheduling:** Existing inbox generation command/schedule; document operator cadence (daily morning).

## Phase 3 — Commitments store

### Why

Pulse Phase 1 and Inbox Phase 2 are **views over existing data**. Meeting actions and Jira follow-ups need **stable open/closed state** for “still owed” tracking without re-parsing markdown or re-firing inbox items.

### `commitment_items` table

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `user_id` | FK | Owner |
| `type` | string | `meeting_action`, `wm_next_action`, `wm_open_question`, `jira_follow_up` |
| `status` | string | `open`, `done`, `snoozed`, `cancelled` |
| `title` | string | Display line |
| `body` | text nullable | Optional detail |
| `project_id` | uuid nullable | FK projects |
| `scope_type` | string nullable | WM scope |
| `scope_key` | string nullable | WM scope |
| `source_thought_id` | uuid nullable | |
| `source_version_id` | uuid nullable | compaction or WM version |
| `external_key` | string nullable | Jira issue key |
| `external_url` | string nullable | |
| `owner_label` | string nullable | From meeting action parse |
| `due_at` | timestamp nullable | Future |
| `snoozed_until` | timestamp nullable | |
| `dedupe_key` | string | Unique per user when open |
| `source_data` | json nullable | |
| `opened_at`, `closed_at` | timestamps | |

Unique partial index: one `open` row per `(user_id, dedupe_key)`.

### Writers (upsert on sync)

- `CommitmentExtractor` invoked from:
  - `SynthesizeMeetingCompactionJob` (after persist) — meeting actions
  - `WorkingMemoryBuilderService` (after validated/external version) — next actions + open questions
  - `SyncUserJiraActivity` — optional `jira_follow_up` when assignee = user and status in active states

### Reader

- Pulse section **Open commitments** reads `commitment_items` where `status = open` (replaces Phase 1 WM/meeting parsing for that section).
- Simple list UI on Pulse with mark done / snooze (reuse Inbox action patterns).

### MCP (stretch)

- `get_attention_overview` — same payload as Pulse page (phase 1 DTO).

## Configuration

```env
FEATURE_ATTENTION_PULSE=false

# config/pulse.php
PULSE_MEMORY_STALE_DAYS=14
PULSE_JIRA_DAYS=14
PULSE_MEETING_ACTION_DAYS=30
PULSE_MAX_MEMORY_HEALTH=10
PULSE_MAX_COMMITMENTS=15
PULSE_MAX_JIRA=15
```

## Security and privacy

- All queries scoped to `auth()->id()`.
- Jira tokens unchanged (per-user credentials).
- No cross-user data on Pulse.

## Acceptance criteria

### Phase 1

- [ ] `GET /pulse` renders four section types when data exists.
- [ ] Memory health shows Fallback badge scopes with link to correct memory route.
- [ ] Jira section links to issue URL when present.
- [ ] Morning brief shows Pulse card when count > 0.
- [ ] Feature flag off → route 404, no nav link.
- [ ] Feature tests cover each section with fixture data.

### Phase 2

- [ ] Four generators registered; dedupe prevents duplicate pending inbox rows.
- [ ] `inbox:generate` (or scheduled job) creates items for fallback memory and recent Jira.
- [ ] Unit tests per generator.

### Phase 3

- [ ] Migration + model + extractor tests.
- [ ] Meeting compaction creates open commitment items.
- [ ] Pulse commitments section reads from `commitment_items`.
- [ ] Mark done closes item; does not re-open unless source changes materially (new dedupe key).

## Rollout

| Week | Deliverable |
|------|-------------|
| 1 | Phase 1 — Pulse page + morning brief card + tests |
| 2 | Phase 2 — two high-value generators (memory fallback, Jira follow-up) |
| 3 | Phase 2 — meeting action + stale project generators |
| 4–5 | Phase 3 — commitments schema, extractors, Pulse integration |

## Open questions (defer unless blocking)

- Should Pulse default to **client-grouped** layout (like `/memory/scopes` Clients section)? *Recommendation: yes for commitments + memory health in Phase 1.5 polish.*
- Per-project commitment owner filter? *Defer to Phase 3.*
- Email digest of Pulse? *Defer; use Inbox + morning brief first.*

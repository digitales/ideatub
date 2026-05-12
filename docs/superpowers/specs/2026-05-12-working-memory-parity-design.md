# Working Memory Parity: Local Agent ↔ IdeaTub Online

**Date:** 2026-05-12
**Status:** Draft
**Scope:** IdeaTub working memory system + Elixirr sync pipeline

## Problem

The local Elixirr `working-memory/current.md` (built by the `elixirr-memory-refresh` agent) is rich and actionable — structured sections covering focus, priorities, changes, questions, risks, actions, and source citations. The IdeaTub online working memory (returned by `get_working_memory` MCP/REST) is thin and link-heavy because:

1. Most Elixirr source material (Slack summaries, automation outputs, meeting notes) never reaches IdeaTub as thoughts.
2. The `elixirr-sync` skill pushes `current.md` as a dated `capture_plan` snapshot thought, not as the persisted working memory for the scope.
3. AI authoring is disabled (`WORKING_MEMORY_AUTHORING_ENABLED=false`), so the legacy assembler runs — it does tag counting and thought truncation, not real synthesis.
4. Even if AI authoring were enabled, the sparse thought corpus would produce thin output.

**Example:** Dezeen has ~410 processed Slack summaries, ~25 automation scans, and 3 structured meeting notes locally. IdeaTub's Dezeen scope has a fraction of these as thoughts, with no meeting compactions.

## Solution

A three-phase approach that delivers immediate parity (Phase 1), grows the online signal corpus (Phase 2), and graduates to independent AI-authored synthesis (Phase 3).

## Phase 1: Upsert Working Memory from External Source

### New Endpoint

A new `upsert_working_memory` MCP method and matching REST endpoint that accepts pre-authored working memory markdown and persists it directly as a `WorkingMemoryVersion`.

**MCP method:** `upsert_working_memory`
**REST endpoint:** `POST /api/thoughts/working-memory/upsert`

**Parameters:**

| Parameter | Required | Description |
|---|---|---|
| `scope_type` | Yes | Scope type (e.g. `project`, `global`, `tag`) |
| `scope_key` | Yes | Scope identifier (e.g. `dezeen`) |
| `content` | Yes | Full working memory markdown |
| `source_label` | No | Origin identifier (e.g. `elixirr-sync`) |

### Processing

1. Parse the markdown into 8 sections by splitting on `## ` headings matching the required section names: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes.
2. Each section's content becomes a `structured_sections` entry with items parsed from the bullet points under that heading.
3. Create or find the `WorkingMemory` record for the user + scope.
4. Create a `WorkingMemoryVersion` with:
   - `build_type`: `external`
   - `authoring_status`: `external`
   - `confidence_score`: 90.0 (high — human/agent-curated)
   - `summary_markdown`: the full input markdown
   - `structured_sections_json`: the parsed sections
   - `references_json`: empty (local file paths aren't web-resolvable)
   - `section_references_json`: stream filter URLs per section (same as AI-authored builds)
5. Update the parent `WorkingMemory` record: set `latest_version_id`, `freshness_state: fresh`, `last_refreshed_at: now()`.

### Canonical Version Resolution

`WorkingMemoryAssembler::payloadFromPersistedMemory()` currently resolves the canonical version by preferring the latest `consolidated` build. This changes to:

- Prefer the latest version where `build_type` is `consolidated` or `external`, ordered by `created_at` descending.
- This means an external upsert takes precedence over an older consolidated build, and a newer consolidated build takes precedence over an older external upsert.

### Sync Skill Update

The `elixirr-sync` skill adds a second step after the existing `capture_plan` snapshot:

1. (Existing) `capture_plan` with `doc_type: plan` — dated snapshot thought for Stream browsability.
2. (New) `upsert_working_memory` with `scope_type: project`, `scope_key: <client>`, `content: <current.md text>`, `source_label: elixirr-sync`.

For project-level working memory (`clients/<client>/projects/<project>/working-memory/current.md`), the scope key follows the existing convention: `<client>/<project>`.

### MCP Tool Registration

Add `upsert_working_memory` to the MCP handler in `McpController.php`, following the same pattern as `get_working_memory`. The method delegates to a new `WorkingMemoryUpsertService` that handles parsing, validation, and persistence.

### Authentication

The new endpoint uses the same OAuth token authentication as existing MCP/API endpoints. No additional auth mechanism needed.

### What This Delivers

- Online working memory matches local `current.md` content.
- Both web UI and MCP `get_working_memory` return the rich version.
- Freshness tracking reflects when the sync last ran.
- No LLM cost.

## Phase 2: Auto-sync High-Signal Sources on Creation

### Sources

| Source | Trigger | MCP method | Expected volume |
|---|---|---|---|
| Meeting notes | `elixirr-meeting-writer` or `elixirr-meeting-notes` skill completes | `capture_meeting` | ~1-2/week per client |
| Automation outputs | Automation writes to `outputs/automations/` | `capture_plan` | ~1-2/day per repo |

Slack summaries are excluded from Phase 2 — high volume, mostly superseded by meetings for working memory purposes.

### Meeting Notes

The `elixirr-meeting-writer` and `elixirr-meeting-notes` skills already produce structured meeting notes locally. The change:

- Add `capture_meeting` as a **required** final step (currently documented as optional).
- Parameters: `content` (full meeting markdown), `doc_type: meeting`, `plan_slug: <meeting-slug>`, `project: <client>`, `section_title: <meeting title>`.

IdeaTub's `ThoughtObserver` automatically dispatches:
1. `SynthesizeMeetingCompactionJob` — produces a `compaction:meeting` version (decisions, actions, risks extracted by LLM).
2. `RefreshWorkingMemoryIncremental` — incremental working memory update ~60 seconds later (delayed to let the compaction persist first, per `meeting_refresh_delay_seconds` config).

### Automation Outputs

The automation runner scripts that produce daily bug scans add a post-run step:

- Call `capture_plan` with `content` (scan markdown), `doc_type: plan`, `project: <client>`, `plan_slug: <automation-id>-<date>`, `section_title: <scan title>`.
- Tags: `automation`, `bug-scan`, `client:<client>`, `repo:<repo-name>`.

### What Phase 2 Delivers

- IdeaTub accumulates real signal between local syncs.
- Meeting compactions — the highest-weighted evidence for the AI composer — are generated automatically.
- Incremental overlay deltas in the working memory response show new activity since the last external upsert.
- The external upsert from Phase 1 still wins as the canonical consolidated/external version.

### Skill Changes

| Skill | Change |
|---|---|
| `elixirr-meeting-writer` | `capture_meeting` becomes required final step |
| `elixirr-meeting-notes` | Same — `capture_meeting` required |
| Automation runner configs | Add post-run `capture_plan` call |

## Phase 3: Backfill Slack + AI Composer Graduation

### Part A: Slack Summary Backfill and Ongoing Sync

**One-time backfill:**

A batch script iterates processed Slack summaries in `outputs/slack/` and calls `capture_plan` for each:
- `doc_type: plan`
- `project: <client>`
- Tags: `slack`, `channel:<channel-name>`, `client:<client>`
- Rate limited: 50 thoughts/minute to avoid flooding
- Consolidated working memory rebuilds deferred until backfill completes

For Dezeen, this is ~410 files across 3 channels (`client-dezeen`, `edx-dezeen`, `dezeen-dev`).

**Ongoing sync:**

The `elixirr-comms-normalizer` skill adds `capture_plan` as a final step when writing a new processed Slack summary. Same pattern as Phase 2 automation outputs.

**Weekly digests:**

With more Slack thoughts in the corpus, the existing `BuildWeeklyDigestsCommand` (already scheduled) produces meaningful weekly digest compactions that compress a week of channel activity into a single compaction for the AI composer.

### Part B: AI Composer Graduation

Once the thought corpus is rich enough (meetings with compactions, automation outputs, Slack summaries with weekly digests):

1. Enable AI authoring: `WORKING_MEMORY_AUTHORING_ENABLED=true`, `features.working_memory_ai_authored=true`.
2. The evidence pack builder assembles: up to 60 raw thoughts + up to 20 compactions (meeting, weekly-digest, topic-digest, research-synth).
3. The composer prompt targets the same 8 sections as the local `current.md`.
4. Validation enforces citation coverage — every claim traces to a source thought.

### Authoring Precedence (Hybrid Period)

During the period when both external upserts and AI-authored builds coexist:

- The canonical version is the newest `WorkingMemoryVersion` where `build_type` is `consolidated` or `external`, ordered by `created_at` descending.
- If the external upsert is newer, it wins. If the AI-authored consolidated build is newer, it wins.
- This allows running both in parallel: the local agent syncs via upsert, the AI composer rebuilds periodically, and you compare quality before fully cutting over.
- Eventually, if the composer proves reliable, the local `elixirr-memory-refresh` → `elixirr-sync` pipeline becomes optional.

### Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Backfill volume (~410 Slack thoughts for Dezeen) | Batch with rate limiting, defer consolidated rebuilds |
| AI composer quality vs local agent | Hybrid precedence rule — external upsert overrides; compare before cutting over |
| LLM cost per consolidated build | `gpt-4o-mini` with evidence pack capped at 60k chars; <$0.05 per build |
| Old Slack summaries (2024-era) adding noise | 180-day consolidation window excludes the oldest; composer prompt prioritizes recency |
| Paid plugin ownership during migration | Not in scope — tracked in Dezeen working memory as a risk/open question |

## Implementation Order

1. **Phase 1** (small): `WorkingMemoryUpsertService`, REST endpoint, MCP method, `elixirr-sync` skill update. Immediate parity.
2. **Phase 2** (small-medium): Meeting skill updates, automation runner hooks. Corpus growth.
3. **Phase 3** (medium): Slack backfill script, comms-normalizer skill update, AI authoring enablement and tuning. Independent synthesis.

Each phase delivers value independently. Phase 2 and 3 are not blocked on each other but are ordered by signal-to-effort ratio.

## Files Affected

### Phase 1 — IdeaTub Codebase

| File | Change |
|---|---|
| `app/Services/WorkingMemory/WorkingMemoryUpsertService.php` | New — markdown parsing, section extraction, version persistence |
| `app/Http/Controllers/Api/McpController.php` | Add `upsert_working_memory` MCP method handler |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Add `POST /api/thoughts/working-memory/upsert` route |
| `routes/api.php` | Register the new route |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Update `resolveCanonicalVersion()` to include `external` build type |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | No changes — external versions bypass the builder |

### Phase 1 — Elixirr Skills

| File | Change |
|---|---|
| `elixirr-sync` skill | Add `upsert_working_memory` call after `capture_plan` |

### Phase 2 — Elixirr Skills

| File | Change |
|---|---|
| `elixirr-meeting-writer` skill | Make `capture_meeting` a required final step |
| `elixirr-meeting-notes` skill | Same |
| Automation runner configs | Add post-run `capture_plan` hook |

### Phase 3 — IdeaTub Codebase + Elixirr Skills

| File | Change |
|---|---|
| Backfill script (new) | Batch `capture_plan` for historical Slack summaries |
| `elixirr-comms-normalizer` skill | Add `capture_plan` as final step for new summaries |
| `.env` | Enable `WORKING_MEMORY_AUTHORING_ENABLED=true` |
| `config/features.php` or equivalent | Enable `working_memory_ai_authored` |

## Deprecation: `online-current.md`

The local `online-current.md` file (e.g. `clients/dezeen/working-memory/online-current.md`) is a manually-created artifact that stores a snapshot of IdeaTub's working memory output. After Phase 1, this file is obsolete — the canonical online working memory is the `get_working_memory` API response, which now returns the rich upserted content. The file can be deleted or left as-is; no workflow depends on it.

## Out of Scope

- Backfill of Teams summaries (none exist for Dezeen yet).
- Changes to the AI composer prompt or evidence pack weighting (use existing logic; tune only if Phase 3 output quality is insufficient).
- Changes to the web UI rendering of working memory (the existing display handles structured sections already).
- Syncing `context/index.md` or client context files to IdeaTub (these are stable framing, not signal).

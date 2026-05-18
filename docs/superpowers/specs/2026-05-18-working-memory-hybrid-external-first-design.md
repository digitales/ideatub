# Working Memory: External-First Hybrid + Version History

**Date:** 2026-05-18  
**Status:** Approved (2026-05-18)  
**Scope:** IdeaTub working memory (UI, REST, MCP) + Elixirr `elixirr-sync` skill  
**Related:** [2026-05-12-working-memory-parity-design.md](./2026-05-12-working-memory-parity-design.md), [2026-05-05-working-memory-design.md](./2026-05-05-working-memory-design.md)

## Problem

Working memory is meant to answer “what matters now” for people and agents. In production, scopes with large thought corpora (e.g. Dezeen, ~600+ linked captures) still show **legacy assembler** output:

- Executive summary: “First-pass synthesis across N thoughts highlights {tag} as the strongest signal.”
- Key concepts = top tags; open questions = metadata lines containing `?`; threads = thought title fragments.
- High confidence scores despite low signal.

Root causes (confirmed in brainstorming + screenshots):

1. **AI authoring is off by default** (`FEATURE_WORKING_MEMORY_AI_AUTHORED` and `WORKING_MEMORY_AUTHORING_ENABLED` both false), so consolidated rebuilds use heuristics only.
2. **Rich curated memory exists locally** (Elixirr `working-memory/current.md`, kept current) but is not persisted as the canonical IdeaTub version for the project scope—or is overwritten by a newer legacy `consolidated` build after “Refresh working memory.”
3. **`upsert_working_memory` is implemented** in IdeaTub but **not wired** in the Elixirr sync pipeline; `capture_plan` snapshots alone do not update canonical memory.
4. **Version history is stored** (`working_memory_versions`) but **not exposed** in UI or API, so regressions (external → thin legacy) are invisible.

## Goals

- Make **external curated memory** the canonical baseline for client/project scopes where `current.md` exists.
- Use **AI consolidation only** for scopes without a fresh external baseline (hybrid, not LLM-everywhere).
- **Protect** external memory from accidental downgrade via manual refresh.
- **Expose read-only version history** (UI + REST + MCP) for canonical builds.
- Keep **one payload contract** for UI and `get_working_memory` (structured 8-section layout when `authoring_status` is `external` or `validated`).

## Non-Goals

- Replacing Elixirr `elixirr-memory-refresh` as the primary author for client memory in phase 1.
- Full Slack backfill (covered in parity spec phase 3; optional later).
- Editable working memory in the UI (read-only history and current view only).
- Deleting compaction versions from the default history list (compactions remain advanced/debug).

## Decisions

| Topic | Decision |
|-------|----------|
| Primary strategy | External-first hybrid (approach 1 from brainstorming) |
| Canonical producers | `external` (upsert) and `consolidated` (AI or legacy); `incremental` is overlay-only |
| Canonical selection | Newest `external` or `consolidated` by `created_at` (existing assembler rule) |
| Refresh when external is fresh | Do not queue legacy consolidated rebuild; optional AI rebuild behind separate action |
| Project `scope_key` | IdeaTub **project UUID** (lowercased), not metadata slug `dezeen` |
| Version history | In scope: list + read single version (phase 1b) |
| History retention | Keep all canonical versions by default; optional config cap later |

## Architecture

### Data already in place

- `working_memories` — one row per user + scope.
- `working_memory_versions` — immutable rows per build (`consolidated`, `incremental`, `external`, `compaction:*`).
- `working_memory_inputs` — thought links for builder-produced versions.
- `WorkingMemoryUpsertService` — parses `current.md`-style markdown into eight sections + `build_type=external`.

### Canonical read path (unchanged contract)

`WorkingMemoryAssembler::forScope()` returns the current assembled payload:

- Baseline: newest `consolidated` or `external`.
- Overlay: newest `incremental` since baseline (existing `overlay_deltas`).

### New: external protection on refresh

When the user triggers **Refresh working memory** (or `ConsolidateWorkingMemory` is dispatched):

1. Load newest authoritative version (`external` or `consolidated`).
2. If it is `external`, `authoring_status=external`, and `created_at` is within **`WORKING_MEMORY_EXTERNAL_PROTECT_DAYS`** (default **14**), then:
   - **Do not** dispatch legacy consolidated build.
   - Return user-visible message: external memory is current; re-run Elixirr sync or use “Rebuild in IdeaTub” if AI is enabled.
3. If AI authoring is enabled and user chooses **Rebuild in IdeaTub** (new explicit action or `?force=1`), run consolidated build with composer (not legacy-only shortcut).

Rationale: today, refresh after upsert creates a **newer** legacy `consolidated` version that wins on date and destroys usefulness (Dezeen screenshots).

### Elixirr sync contract (phase 1)

After existing `capture_plan` snapshot (Stream browsability), **require**:

```text
upsert_working_memory(
  scope_type: "project",
  scope_key: "<ideatub-project-uuid>",
  content: <full current.md>,
  source_label: "elixirr-sync"
)
```

**Project UUID mapping:** Elixirr client config must store the IdeaTub project id (e.g. `019e0705-5591-73e9-be2e-0fb9c86b269a` for Dezeen). Metadata slug `dezeen` is not a valid `scope_key` for project scope in IdeaTub.

**One-time production fix:** Run upsert once for Dezeen with current `current.md` before or in parallel with skill rollout.

### AI for non-external scopes (phase 3)

Enable `FEATURE_WORKING_MEMORY_AI_AUTHORED` + `WORKING_MEMORY_AUTHORING_ENABLED` only where:

- No `external` version in the last N days, or
- User explicitly forces IdeaTub rebuild.

Composer uses evidence pack (thoughts + compactions). Does not replace external baseline unless newer `consolidated` is intentionally produced and validated.

## Version history (phase 1b)

### Requirements

Users and agents can inspect **prior canonical snapshots** without re-reading Stream.

### API

**REST**

- `GET /api/thoughts/working-memory/versions?scope_type=&scope_key=`  
  Returns paginated list (newest first). Default filter: `build_type` in `external`, `consolidated`. Query `include=compactions` to add `compaction:*`.
- `GET /api/thoughts/working-memory/versions/{id}`  
  Returns full version payload (same shape as embedded sections in current read, plus metadata).

**MCP**

- `list_working_memory_versions` — same filters as REST.
- `get_working_memory_version` — `version_id` required.

List item fields (minimum):

- `id`, `created_at`, `build_type`, `authoring_status`, `confidence_score`, `source_label` (from `build_diagnostics_json.source_label` when present), `citation_coverage` (nullable).

### UI

On `/memory` and `/projects/{project}/memory`:

- **History** control opens a panel or route listing versions (date, type, source).
- Selecting a version shows **read-only** structured sections (reuse `memory.show` partials with `readOnly` flag).
- Current canonical remains default; history entries are not editable.
- Badge on current view when canonical is `external`: “Synced from agent” + last version timestamp.

### Retention

- **v1:** No automatic deletion of `external` / `consolidated` / `incremental` versions.
- Compaction retention unchanged (`compaction_retention` config).
- Future: `WORKING_MEMORY_CANONICAL_VERSION_RETAIN_COUNT` (optional) prunes oldest canonical rows per scope, never deleting the newest `external`.

## UI and MCP: current view improvements

| Surface | Change |
|---------|--------|
| Memory page | Prefer eight-section layout for `external` / `validated` (already gated on `authoring_status`) |
| Details card | Show `source_label`, `build_type`, version `created_at` |
| Refresh button | Split or relabel: **Sync from agent** (help link) vs **Rebuild in IdeaTub** (respects external guard) |
| `get_working_memory` | Document that client scopes expect `upsert_working_memory`; include `canonical_version_id` and `canonical_created_at` in payload for agent staleness checks |

## Phased rollout

| Phase | Deliverable | Outcome |
|-------|-------------|---------|
| **1a** | External guard on refresh; Dezeen one-time upsert; `elixirr-sync` upsert step + UUID mapping | Rich memory live on ideatub.com |
| **1b** | Version list/read API + MCP + UI history panel | Auditable changes; debug overwrites |
| **2** | Required `capture_meeting`; automation `capture_plan` hooks (parity spec); help at `/help/working-memory-corpus-sync` | Stronger incremental overlay between syncs |
| **3** | `working-memory:import-captures`; consolidate `--only-without-external` / `--force`; AI env flags for non-external scopes | Global and non-Elixirr projects |

Phases 2 and 3 match [2026-05-12-working-memory-parity-design.md](./2026-05-12-working-memory-parity-design.md); this spec adds **external protection** and **version history** as first-class requirements.

## Testing

### Feature tests

- Upsert creates `external` version; `get_working_memory` returns structured sections.
- Refresh with fresh `external` does **not** create new legacy `consolidated`.
- Force rebuild (when implemented) creates `consolidated` and can supersede older external by date.
- Version list returns only user-owned versions; single-version read matches persisted JSON.
- Project scope uses UUID normalization (case-insensitive).

### Manual (Dezeen)

1. Upsert `current.md` to project UUID scope.
2. Confirm UI shows eight sections (not executive summary / tag list).
3. Click refresh — confirm no new thin consolidated version.
4. Open history — see external version row with `elixirr-sync` label.

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Wrong `scope_key` in sync (slug vs UUID) | Document mapping; validation error if project scope key is not UUID-shaped when `source_label=elixirr-sync` (optional strict mode) |
| Stale external if sync stops | Freshness badge + `STALE` when `last_refreshed_at` exceeds TTL; overlay still shows new captures |
| DB growth from version history | Optional retain count later; compactions already capped |
| User expects Refresh to update memory | Copy and split actions; message when guard blocks legacy rebuild |

## Files affected (implementation reference)

| Area | Files |
|------|--------|
| Refresh guard | `WorkingMemoryRefreshController`, `ConsolidateWorkingMemory` job or `WorkingMemoryBuilderService` entry |
| Config | `config/working_memory.php` — `external_protect_days` |
| Version API | `ThoughtsApiController`, `routes/api.php`, `McpController` |
| Version UI | `MemoryController`, `resources/views/memory/*` |
| Elixirr (out of repo) | `elixirr-sync` skill, client → project UUID map |

## Success criteria

- Dezeen project memory matches local `current.md` quality (eight sections, actionable bullets).
- MCP `get_working_memory` returns the same structured content agents would get from local file.
- Manual refresh cannot replace a fresh `external` version with legacy assembler output.
- Users can open prior canonical versions from the UI and see when memory changed.

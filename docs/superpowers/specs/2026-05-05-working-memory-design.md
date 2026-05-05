# Working Memory in IdeaTub (Global + Project)

## Overview

IdeaTub can support a first-class working memory layer that distills a large thought corpus into high-signal context for people and agents. The goal is to maintain a continuously updated "what matters now" memory at two levels:

- Global working memory for the full user corpus.
- Project working memory segmented by project.

This design assumes an initial corpus of approximately 995 ideas and targets native support inside IdeaTub (not only agent-managed markdown snapshots).

## Goals

- Create a native, queryable working memory artifact in IdeaTub.
- Maintain both global and project-scoped memory.
- Use a hybrid refresh strategy:
  - Event-driven incremental updates on new/updated thoughts.
  - Scheduled consolidation rebuilds for quality and drift control.
- Preserve traceability from memory statements back to source thoughts.
- Keep a migration path from metadata-defined projects to first-class project entities.

## Non-Goals (v1)

- Full project management or workflow orchestration features.
- Perfect autonomous concept taxonomy without user correction tools.
- Replacing raw thought search; working memory augments existing search.

## Approaches Considered

### Option A: Persisted working-memory snapshots

Create and store versioned memory snapshots per scope.

Pros:
- Fast, stable reads.
- Auditable version history.
- Lower repeated summarization cost.

Cons:
- Additional schema/job complexity.
- Staleness risk if refresh fails.

### Option B: Query-time synthetic memory only

Generate working memory on demand from raw thought retrieval.

Pros:
- Always current by definition.
- Minimal schema changes.

Cons:
- Higher latency and token cost.
- Lower output consistency across runs.
- Harder quality inspection.

### Option C: Persisted memory + live overlay (recommended)

Use persisted snapshots plus a lightweight "recent delta" overlay at read time.

Pros:
- Best balance of stability and freshness.
- Better resilience to delayed consolidations.

Cons:
- Highest implementation and testing complexity.

## Decision

Implement Option C in phases, starting with Option A foundations:

1. Build persisted global + project snapshots.
2. Add event-driven delta updates.
3. Add read-time overlay of latest deltas on consolidated memory.

This sequence delivers value early while reducing initial complexity.

## Scope Model and Project Partitioning

Working memory scopes:

- `global`: one memory per user.
- `project`: one memory per project context per user.

Project partitioning strategy:

- v1: metadata-first using existing thought/project metadata conventions.
- v2: introduce optional first-class `projects` entities and map scope keys to project IDs.

The API contract should remain stable while the backing identifier transitions from metadata value to project entity ID.

## Data Model

### `working_memories`

Canonical memory record per user + scope.

Suggested fields:
- `id`
- `user_id`
- `scope_type` (`global` | `project`)
- `scope_key` (`global` or project identifier)
- `latest_version_id` (nullable)
- `freshness_state` (`fresh` | `degraded` | `stale`)
- `last_refreshed_at`
- `created_at`, `updated_at`

### `working_memory_versions`

Immutable snapshots.

Suggested fields:
- `id`
- `working_memory_id`
- `build_type` (`incremental` | `consolidated`)
- `summary_markdown`
- `key_concepts_json`
- `active_threads_json`
- `open_questions_json`
- `next_actions_json`
- `confidence_score` (bounded numeric)
- `source_window_start`, `source_window_end`
- `created_at`

### `working_memory_inputs` (recommended)

Traceability links between version and source thoughts.

Suggested fields:
- `id`
- `working_memory_version_id`
- `thought_id`
- `contribution_type` (`primary` | `supporting`)
- `weight`
- `created_at`

## Refresh Pipeline (Hybrid)

### Event-driven incremental refresh

Trigger on new thought capture or meaningful thought updates:

1. Determine affected scopes:
   - Always user `global`.
   - User `project` scope when thought has project metadata.
2. Retrieve recent candidate thoughts for scope.
3. Build incremental delta memory artifact.
4. Persist new version (`build_type=incremental`) and update freshness timestamps.

### Scheduled consolidation refresh

Run on schedule (for example nightly):

1. Recompute from canonical source window (default **180 days**, configurable; pinned long-term items are a future enhancement).
2. Recluster concepts and threads.
3. Generate consolidated memory sections with stable schema.
4. Persist new version (`build_type=consolidated`) and mark as latest stable base.

### Read-time assembly

Return:
- Latest consolidated version.
- Plus most recent incremental delta(s), if available and fresh.

If no version exists, generate once on read, persist, and return.

## Memory Synthesis Output Shape

Each version should produce consistent sections:

- Executive summary
- Key concepts
- Active threads
- Open questions
- Next actions
- Risks or contradictions

Each major statement should carry source references (IDs or links) for explainability.

## Error Handling and Reliability

- If refresh fails, keep last known good version active.
- Record failure metadata and retry with backoff.
- Expose freshness status on API and UI.
- Mark stale memory when no successful refresh occurs within threshold.
- Detect contradictory updates and lower confidence until reconciliation.

## User Controls

To keep memory trustworthy, provide controls:

- Pin concept/thread to protect important long-term context.
- Demote or exclude noisy concepts.
- Trigger force rebuild for a scope.
- Merge/split project scopes when metadata partitioning is imperfect.

## Testing Strategy

### Unit tests

- Scope resolution for global/project mapping.
- Build pipeline behavior for incremental and consolidation jobs.
- Freshness-state transitions under success/failure/staleness windows.
- Confidence scoring bounds and schema validity.

### Feature tests

- New thought updates global + matching project memory.
- Scheduled consolidation replaces baseline while preserving traceability.
- Read path overlays incremental delta on consolidated snapshot correctly.
- Failed refresh preserves last known good memory.

### Regression tests

- Metadata-first scope keys migrate cleanly to project IDs.
- Memory output remains schema-stable across pipeline iterations.
- Permissions: user isolation across all memory reads/writes.

## Advantages

- Higher-signal context for users and agents.
- Faster retrieval workflows and reduced prompt overhead.
- Better continuity for project work.
- Lower repeated token/cost footprint.
- Auditable evolution of understanding via version history.

## Disadvantages and Risks

- Added operational complexity (jobs, monitoring, retries, migrations).
- Potential summary drift from source truth.
- Staleness when refresh is delayed or broken.
- Over-compression can remove important nuance.
- Early project partition quality depends on metadata consistency.

## Mitigations

- Always include source trace links.
- Expose freshness + confidence visibly.
- Provide explicit user correction controls.
- Add reconciliation checks against source corpus.
- Run periodic consolidation regardless of event-driven updates.

## Rollout Plan

### Phase 1: Foundations

- Add schema (`working_memories`, `working_memory_versions`, `working_memory_inputs`).
- Implement consolidated build for global scope.
- Expose read API and internal admin diagnostics.

### Phase 2: Project scope

- Add metadata-based project segmentation.
- Build per-project consolidated memory.
- Add project-level UI surfaces.

### Phase 3: Hybrid refresh

- Add event-driven incremental updates.
- Add read-time overlay logic.
- Add freshness/confidence indicators and failure alerts.

### Phase 4: First-class project entities (optional)

- Introduce project model and mapping migration.
- Preserve API contract and scope semantics.

## Resolved decisions

| Topic | Decision |
|-------|----------|
| Consolidation window | **180 days** by default (configurable). Consolidated builds only include thoughts whose `created_at` falls within this rolling window. |
| Archived / low-signal thoughts | **No separate archived pipeline.** Older content naturally drops out of consolidation via the window; no extra exclusion rules or refresh hooks for archived rows in v1. |
| UI | **Both:** a dedicated working-memory page and a project-page module (same underlying API; surfaces can ship in separate phases). |
| Confidence (v1) | **Both:** retain heuristic scoring as the baseline and add **optional model-assisted** refinement when API keys and quotas allow (feature-flagged or env-gated). |

## Success Criteria

- Users can retrieve a trusted global working memory and project working memory in one call.
- Memory freshness remains within SLA for both scopes.
- Agent workflows show reduced context assembly time and token usage.
- Users can correct memory quality without editing raw source thoughts.

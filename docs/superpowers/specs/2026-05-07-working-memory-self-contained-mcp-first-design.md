# Design: Working Memory Self-Contained and MCP-First

**Status:** Draft — design validated in chat, pending written-spec review  
**Date:** 2026-05-07

## Goal

Make IdeaTub the canonical system of record for working memory and expose the same evidence-backed memory contract to downstream agents through MCP.

This design targets two concrete problems:

- Working memory output can be low signal when built from mixed global corpus instead of strict client scope.
- Some generated memory bullets have title/description text without resolvable links or citations.

## Outcomes

- IdeaTub stores and serves the canonical client memory state with no external file dependency.
- MCP `get_working_memory` returns a stable, structured, citation-rich payload for agent consumption.
- Every required memory bullet is evidence-backed with usable links.
- Client memory remains isolated from unrelated global content.

## Non-goals

- Replacing existing capture surfaces (`capture_thought`, `capture_plan`, meeting aliases, etc.).
- Introducing a second memory store outside IdeaTub.
- Removing optional global memory; global remains available as a separate non-canonical view.

## Architecture

### Canonical source of truth

- Canonical memory source is IdeaTub data only: captured thoughts, plans/specs/decisions/dev/support docs, meetings, research, and metadata.
- Client memory is built from scoped IdeaTub evidence, not from local filesystem memory files.

### Dual outputs

- **Canonical client memory:** strict client/project scope (for example `scope_type=project`, `scope_key=dezeen`).
- **Global overview memory:** optional personal synthesis; never used as fallback evidence for client-scoped memory.

### Agent distribution layer

- MCP is the primary distribution interface for memory to other agents.
- `get_working_memory` is the canonical read endpoint for both UI and agent consumers.

## MCP-Ready Memory Contract

Return one canonical payload shape for UI and agents.

### Top-level fields

- `scope_type`, `scope_key`
- `freshness_state` (`fresh`, `degraded`, `stale`)
- `confidence_score` (0-100)
- `last_refreshed_at`
- `baseline_build_type` (`consolidated`, `incremental`)
- `citation_coverage`
- `authoring_status` (`validated`, `fallback`, `disabled`)
- `validation_error` (nullable)
- `build_diagnostics` (required/cited counts plus reason codes)
- `effective_consolidation_window_days`
- `input_count`

### Structured sections

`structured_sections` maps section title to an array of bullets. Canonical section set:

- `Current Focus`
- `Active Priorities`
- `Recent Changes`
- `Open Questions`
- `Risks / Blockers`
- `Next Actions`
- `Latest Signals`
- `Source Notes`

### Bullet schema

Each bullet includes:

- `id`
- `text`
- `importance`
- `fallback_mode` (`direct`, `section_bundle`)
- `citations[]`

### Citation schema

Each citation includes:

- `type`
- `label`
- `url` (canonical IdeaTub permalink)
- optional `thought_id`, `source_ref`, `confidence`

### Reference indexes

- `references[]`: deduped source index for the snapshot.
- `section_references[]`: section-level stream-filter links to audit source sets quickly.

## Citation and Link Model

The citation model must prevent non-actionable placeholder outputs.

### Per-bullet citations

- Required sections must include valid citations for each bullet.
- Citation URLs must be resolvable IdeaTub links, not plain-text source descriptions.

### Link targets

- **Primary:** thought/document permalinks for granular evidence.
- **Secondary:** section-level stream-filter URLs for rapid review of all evidence behind a section.

### Resolution rules

- Prefer direct thought IDs when known.
- If only metadata/source refs exist, resolve to a concrete thought before publishing citation.
- If no concrete source can be resolved for a required bullet, fail validation for that bullet.

## Build and Refresh Pipeline

Use a hybrid refresh model.

### Triggers

- **Event-driven incremental:** refresh on new scoped captures.
- **Scheduled consolidation:** periodic scope sweep to re-rank, dedupe, and stabilize.

### Stages

1. Scope filter and candidate selection.
2. Evidence pack build.
3. AI-authored section generation.
4. Output validation (sections, citations, links, coverage).
5. Version persistence and freshness update.

## Quality Gates and Fallback

### Required validations

- Canonical required sections are present.
- Citation coverage threshold passes required minimum.
- Citation entries have both non-empty labels and valid URLs.

### Failure handling

- **Soft failure:** deterministic fallback summary, `authoring_status=fallback`, diagnostics included.
- **Hard failure:** retain last known good version, mark freshness degraded, expose validation failure context.

## Scope Isolation Rules

- Client-scoped memory must only include data matching client/project scope contract.
- Global memory is not eligible as fallback evidence for client scope.
- Untagged/unscoped items are excluded unless explicit scope binding exists.

## Rollout Plan

### Phase A — Link correctness

- Enforce real permalink citations in builder output.
- Validate citation URLs during working memory validation.

### Phase B — Section audit links

- Add section-level stream-filter links (`section_references`) to persisted payload.

### Phase C — Strictness increase

- Raise citation coverage threshold after backfill quality stabilizes.

### Phase D — Observability

- Track fallback rate, uncited required bullets, broken links, and stale-memory rate.

## Acceptance Criteria

- Required sections contain no uncited bullets.
- Citation URLs are resolvable in IdeaTub.
- Agents can move from a memory bullet to backing evidence in one step.
- Client memory remains isolated from unrelated global signals.
- MCP payload contract is stable and identical for UI and agents.

## Implementation Checklist

- Add or verify canonical permalink builder for citations.
- Add or verify citation URL validation in working memory validator.
- Add `section_references` stream-filter links to payload and persistence.
- Confirm scoped selector never mixes unrelated global signals into client memory.
- Ensure diagnostics fields are consistently emitted for agent reliability.
- Rebuild active client scopes after rollout to backfill citation quality.

## Risks and Mitigations

- **Risk:** strict citation gates increase fallback frequency initially.  
  **Mitigation:** phased rollout and backfill for high-value scopes first.
- **Risk:** existing historical data may lack source metadata needed for link resolution.  
  **Mitigation:** resolve via thought ID when available; add metadata normalization during capture paths.
- **Risk:** agent consumers diverge if alternate payloads emerge.  
  **Mitigation:** enforce one canonical `get_working_memory` contract for all consumers.

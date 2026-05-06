# Working Memory AI Authoring to `current.md` Structure (All Scopes)

## Overview

This spec defines how IdeaTub working memory should be authored by AI in a consistent `current.md`-style format, with traceable references for each major claim.

The target is to use this authored format across all working-memory scopes:

- `global`
- `project`
- `insights`
- `tag`

The authoring pipeline uses a hybrid approach: deterministic evidence assembly first, then constrained AI writing, then strict validation before publish.

## Goals

- Produce a consistent, high-signal working-memory narrative matching the `current.md` structure.
- Use AI as the author of final section content, not only as a post-processor.
- Enforce references where possible, preferring internal thought links and falling back to source file/doc links.
- Preserve reliability guarantees: last-known-good behavior, freshness states, and traceability metadata.
- Keep compatibility with existing API, MCP, and web surfaces.

## Non-Goals

- Replacing raw thought search, Stream, or source-document navigation.
- Building a full in-app rich-text editor for manual rewriting of authored memory.
- Perfectly eliminating all model drift in one release.

## Resolved Decisions

| Topic | Decision |
|-------|----------|
| Authoring mode | AI writes the final working-memory content. |
| Scope rollout | Use authored format for all scopes (`global`, `project`, `insights`, `tag`) in first rollout. |
| Reference style | Use both internal thought links and source file/doc links; prefer thought links when available. |
| Pipeline choice | Hybrid deterministic evidence pack + constrained AI authoring + post-generation validation. |
| Incremental handling (v1) | Regenerate a full authored snapshot from baseline + delta evidence for consistency. |

## Target Output Structure

Every authored working-memory version uses this section schema:

1. `Current Focus`
2. `Active Priorities`
3. `Recent Changes`
4. `Open Questions`
5. `Risks / Blockers`
6. `Next Actions`
7. `Latest Signals`
8. `Source Notes`

Notes:

- Section order is fixed in v1.
- Empty sections are allowed only when explicitly marked as no material changes (for example, in `Recent Changes`).
- Each major bullet in priority/risk/action/signal sections should carry at least one reference.

## Architecture

### 1) Scope Resolution

Reuse existing scope resolution logic:

- Always include `global`.
- Include `project` based on thought/project mapping.
- Include `insights` for research-oriented corpus behavior.
- Include `tag` for natural and forced tags.

### 2) Evidence Pack Builder

Before AI generation, build a deterministic evidence pack per scope:

- Candidate input list (thoughts, source summaries, relevant artifacts).
- Recency + salience ranking.
- Scope-specific filtering and weighting.
- Pre-resolved reference targets:
  - Preferred: internal thought route/ID references.
  - Fallback: source file/doc references when internal link is unavailable.
- Conflict hints (for example, contradictory status markers).

This evidence pack becomes the only model input for authored memory generation.

### 3) AI Author

Invoke AI with:

- Fixed section schema.
- Explicit style constraints (clear, concise operational state).
- Instruction to avoid unsupported claims.
- Instruction to attach references for major bullets where evidence exists.

### 4) Validation and Enforcement

Post-generation validator enforces:

- Presence of all required sections.
- Minimum citation coverage for major bullets in key sections (default `>= 90%`, configurable in `config/working_memory.php`).
- Reference resolution validity (thought link or valid source link).
- Contradiction checks for obvious state conflicts.
- Duplicate/near-duplicate bullet suppression.

If validation fails:

- Reject publish for that candidate output (`hard fail` when section presence or reference validity checks fail; `soft fail` for coverage below threshold, with prior version retained).
- Keep the last known good version active.
- Record validation failure metadata for observability.

### 5) Persistence

Persist authored output and traceability in existing working-memory tables:

- `summary_markdown` stores the full authored markdown.
- Structured section payload (JSON) stores normalized section content for API/UI consumers.
- Citation metadata stores per-bullet reference mappings.
- `working_memory_inputs` continues to record source thought contributions.

## Scope-Specific Behavior

Authoring structure remains identical across scopes; evidence selection differs by scope:

- `global`: all eligible user signals, ranked by recency and impact.
- `project`: project-bound signals only, with project context bias for focus and actions.
- `insights`: research-heavy/cross-corpus signals, still rendered with the same section schema.
- `tag`: normalized tag-bound signals, including user-forced tags regardless of threshold.

## Reliability and Freshness Behavior

Existing freshness model remains:

- Successful validated publish updates freshness timestamps and version pointers.
- Failed authoring or failed validation keeps last-known-good and updates status toward `degraded`/`stale` per thresholds.
- Consolidation and incremental scheduling remain in place, but synthesis stage uses the new authoring pipeline.

## API, MCP, and UI Contract

Keep current read paths and add explicit structured fields:

- Markdown output: authored narrative in fixed section order.
- Structured output: section array/object for deterministic rendering.
- Reference output: per-item reference data for clickable citations.

UI behavior:

- Render authored markdown or section objects without markdown scraping.
- Keep existing freshness/confidence/details affordances.
- Add lightweight citation affordances where references exist (for example chips or links per bullet).

## Rollout Plan

1. Add feature flag for AI-authored working memory format.
2. Enable in development/internal environments across all scopes.
3. Observe metrics:
   - Citation coverage rate.
   - Validation failure rate.
   - Generation latency.
   - Cost per build.
4. Promote to broader default once thresholds are acceptable:
   - Citation coverage median `>= 95%` over 7 days.
   - Validation hard-fail rate `<= 2%` over 7 days.
   - P95 generation latency within agreed SLA (initial target: `<= 12s` per scope build).
   - Cost per build within configured budget guardrail.
5. Keep deterministic summary path as rollback mechanism.

## Testing Strategy

### Unit Tests

- Evidence pack scope filtering and ranking.
- Reference-preference logic (thought link first, fallback to source link).
- Section validator behavior for missing sections/citations.
- Contradiction and duplicate detection helpers.

### Feature Tests

- End-to-end authored generation for each scope (`global`, `project`, `insights`, `tag`).
- Failure path preserves last-known-good version.
- API payload includes markdown + structured sections + references.
- Forced-tag scope receives authored output even below natural thresholds.

### Regression Tests

- Existing freshness transitions unchanged for success/failure/staleness timing.
- Existing permissions and user isolation remain intact for all scopes.
- Existing readers remain compatible if they only consume markdown field.

## Risks and Mitigations

- Drift or hallucination risk:
  - Mitigate with deterministic evidence pack and strict validator.
- Citation undercoverage:
  - Mitigate with generation constraints and reject-on-threshold-failure policy.
- Cost/latency increase:
  - Mitigate with scope-aware candidate caps, caching of evidence pack assembly, and staged rollout.
- Overly rigid format:
  - Mitigate by allowing controlled future section evolution behind schema versioning.

## Success Criteria

- All scopes return authored working memory in the standardized `current.md` section structure.
- Major bullets in priority/risk/action/signal sections include resolvable references with default coverage `>= 90%` (configurable).
- Failed generations never replace last-known-good memory.
- API/MCP/UI can consume both markdown and structured section/reference data without lossy parsing.

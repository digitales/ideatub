# Working Memory Detailed Narrative + 100% Citation Coverage

## Overview

This spec defines a stricter working-memory authoring model that produces richer, `current.md`-style operational detail while enforcing citation coverage for every required bullet.

The immediate problem is that current working-memory output is too shallow compared to the expected detailed format and lacks reliable source linkage per item. The target state is high-detail memory with resolvable links for all required claims.

## Goals

- Increase output depth to match `current.md` quality (clear focus, priorities, changes, blockers, actions, and signals).
- Enforce citation coverage for all required bullets using a dual-link preference (`thought` + `source` when available).
- Preserve fallback behavior that still keeps traceability when direct per-bullet thought links are unavailable.
- Keep existing reliability guarantees: validation gates, last-known-good retention, and freshness transitions.
- Maintain compatibility for markdown consumers while adding structured citation metadata for API/UI/MCP.

## Non-Goals

- Replacing Stream or raw thought navigation.
- Requiring manual editorial workflows to achieve citation compliance.
- Introducing user-facing rich-text editing in this phase.

## Decisions

| Topic | Decision |
|---|---|
| Authoring approach | Evidence-first deterministic pack + constrained AI authoring |
| Citation target policy | Include both thought link and source link when available |
| Coverage target | 100% citation coverage for required sections/items |
| Missing direct citation behavior | Use section-level source bundle fallback |
| No-citation behavior | Drop item (or fail candidate output if section minimum would be violated) |

## Target Output Structure

The authored output remains in fixed `current.md`-parity section order:

1. `Current Focus`
2. `Active Priorities`
3. `Recent Changes`
4. `Open Questions`
5. `Risks / Blockers`
6. `Next Actions`
7. `Latest Signals`
8. `Source Notes`

Optional narrative preface sections (for example `Executive Summary`) may remain, but required operational sections above are the validation anchor.

## Architecture

### 1) Deterministic evidence assembly

For each scope (`global`, `project`, `insights`, `tag`), build a normalized evidence pack before generation:

- Ranked focus statements and active priorities.
- Recent delta statements (what changed and why it matters).
- Open questions, blockers/risks, and next actions.
- Latest signal candidates with source metadata.
- Pre-resolved citation candidates:
  - `thought_link` (primary internal target).
  - `source_link` (source document/file target).
  - `section_source_bundle` (fallback source set).

The AI stage is restricted to this pack and cannot introduce unsupported claims.

### 2) Constrained AI authoring

AI generates richer narrative bullets from evidence-pack items with fixed schema and style constraints:

- Operational, decision-useful language (not generic summaries).
- One claim per bullet where practical.
- Required citation metadata for each required bullet.
- Dual-link emission when both targets are available.

### 3) Validation gate (hard + soft)

Hard validation requirements:

- Required sections exist and are in fixed order.
- Required sections have valid items (or explicit allowed empty markers such as no material changes for `Recent Changes`).
- Every required item has at least one resolvable citation path (`thought`, `source`, or `bundle`).
- Citation targets resolve to valid routes/references.

Soft validation requirements:

- Duplicate and near-duplicate suppression.
- Contradiction heuristics (for example, resolved and blocked states for the same thread).
- Minimum specificity/detail checks for generated bullets.

Hard failures block publish and retain last-known-good. Soft failures degrade quality score and emit diagnostics.

## Output Contract (Structured + Markdown)

### Top-level fields

- `summary_markdown`: Full human-readable authored output.
- `sections`: Structured ordered section payload.
- `citation_coverage`: Coverage counters and percentage.
- `build_diagnostics`: Validator outcomes and reason codes (internal/admin, optionally exposed).

### Section/item schema

Each section provides `items[]`, where each item includes:

- `id`
- `text`
- `importance` (optional ranking)
- `citations[]`
- `fallback_mode` (`direct` | `section_bundle`)

### Citation object

Each citation object includes:

- `type` (`thought` | `source` | `bundle`)
- `label`
- `url`
- `thought_id` (when `type=thought`)
- `source_ref` (when `type=source` or `bundle`)
- `confidence` (optional)

Ordering rule:

- Emit `thought` citation first when present.
- Emit `source` citation second when present.
- Emit `bundle` citation only when direct linkage is unavailable.

## Citation and fallback policy

Required sections must satisfy full citation coverage:

- `Current Focus`
- `Active Priorities`
- `Recent Changes`
- `Open Questions`
- `Risks / Blockers`
- `Next Actions`
- `Latest Signals`

Fallback rules:

1. Use direct `thought` citation when available.
2. Add `source` citation when available.
3. If no direct thought target exists, use section-level `bundle` citation(s).
4. If no valid citation path exists, remove the item from candidate output.
5. If removal breaks required section minimums, fail the candidate output (hard fail).

## Reliability and Freshness

- Failed candidate publish never replaces last-known-good output.
- Freshness transitions (`fresh`, `degraded`, `stale`) continue to age based on successful refresh timing.
- Manual refresh can trigger immediate regeneration attempts but cannot bypass validation rules.
- Repeated hard-failures trigger retry/backoff and fallback summarizer policy where configured.

## UI/API Behavior

- Render structured sections directly instead of scraping markdown.
- Keep markdown response for backward compatibility.
- Show citation chips/links on each item.
- Distinguish `fallback_mode=section_bundle` visually so users can see grouped evidence vs direct evidence.

## Rollout Plan

1. **Contract + validator phase**
   - Add structured citations schema and hard/soft validator layers.
2. **Evidence enrichment phase**
   - Expand deterministic extraction to support deeper `current.md`-style detail.
3. **Authoring upgrade phase**
   - Enable constrained schema-first AI author with strict citation enforcement.
4. **Surface parity phase**
   - Render citation affordances in all working-memory UI/API/MCP surfaces.
5. **Strict-default phase**
   - Promote to production default once coverage and latency metrics are stable.

## Testing Strategy

### Unit tests

- Citation resolver priority (`thought` first, then `source`, then `bundle`).
- Coverage calculator for required sections/items.
- Hard validator reason codes (`missing_citation`, `invalid_link`, `empty_required_section`, `bad_section_order`).
- Soft validator heuristics (duplicates, contradictions, low-specificity text).

### Feature tests

- End-to-end generation for `global`, `project`, `insights`, and `tag` scopes with full coverage validation.
- Missing direct links correctly downgrade to section bundle citations.
- No valid citation path causes item removal and hard-fail when section minimum is violated.
- Failed builds preserve last-known-good version and freshness behavior.

### Regression tests

- Existing markdown consumers remain functional.
- Scope isolation and permissions remain unchanged.
- Freshness-state transitions remain unchanged under success/failure scenarios.

## Risks and Mitigations

- **Risk:** More candidate outputs fail under strict citation rules.
  - **Mitigation:** Improve evidence-pack quality and allow section-bundle fallback where direct links are impossible.
- **Risk:** Latency/cost increase due to richer generation and validation.
  - **Mitigation:** Candidate caps, caching in evidence assembly, and staged rollout.
- **Risk:** Over-constrained output can reduce readability.
  - **Mitigation:** Maintain constrained but natural authoring style, with clear operational language.

## Success Criteria

- Required working-memory sections consistently match `current.md`-level detail quality.
- Required sections achieve 100% citation coverage through direct or bundle citations.
- Failed candidate generations never replace valid prior memory snapshots.
- UI/API/MCP can display item-level citation links with explicit fallback indicators.

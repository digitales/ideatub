# Design: Working Memory Compactions and Narrative Authoring

**Status:** Draft — pending user review
**Date:** 2026-05-07

## Relationship to other specs

Builds on:

- [`2026-05-05-working-memory-design.md`](2026-05-05-working-memory-design.md) — base persistence (`working_memories`, `working_memory_versions`, `working_memory_inputs`).
- [`2026-05-06-working-memory-ai-authored-structure-design.md`](2026-05-06-working-memory-ai-authored-structure-design.md) — structured sections payload.
- [`2026-05-06-working-memory-detailed-citation-coverage-design.md`](2026-05-06-working-memory-detailed-citation-coverage-design.md) — citation coverage gates.
- [`2026-05-07-working-memory-self-contained-mcp-first-design.md`](2026-05-07-working-memory-self-contained-mcp-first-design.md) — IdeaTub as canonical source; every cited bullet must resolve to an IdeaTub permalink.

This spec replaces `WorkingMemoryAiAuthorService`'s deterministic placeholder with a model-backed composer and introduces a persisted compaction layer so working memory output reaches the narrative quality of the external Codex pipeline.

## Goal

Produce narrative, evidence-backed working-memory output equivalent to the dezeen client-level reference (`Documents/elixirr/clients/dezeen/working-memory/current.md`) using only IdeaTub data, with no external pipeline dependency.

## Outcomes

- Working memory `summary_markdown` reads as decision-ready prose, not stub bullets.
- A persisted compaction layer mediates between raw thoughts and the canonical narrative; meeting captures and periodic digests become first-class evidence.
- The composer cites compaction permalinks and thought permalinks; both resolve in IdeaTub.
- `WorkingMemoryAiAuthorService` becomes a thin wrapper over a model-backed composer with explicit promotion rules per source-type.

## Non-goals

- Importing external Slack/Teams/JSON exports. Out of scope; not required for the user.
- Migrating Codex skills `competitive-analysis`, `deal-memo-drafting`, `financial-model-review`, `elixirr-comms-normalizer`, `elixirr-follow-up-comms`, `elixirr-workspace-*`, `elixirr-raw-meeting-dropzone`, `elixirr-automation-output-publisher`, `elixirr-sync`, `codex-primary-runtime`. They stay as agent-side or are obsoleted by the self-contained design.
- Changing capture surfaces (`capture_thought`, `capture_plan`, `capture_meeting`, etc.).
- Replacing the existing `OpenRouterService` or introducing a second model gateway.

## Codex skill migration map

| Codex skill | IdeaTub destination |
|---|---|
| `elixirr-memory-refresh` | `WorkingMemoryAiAuthorService` (model-backed composer) + evidence selector preferring compaction versions |
| `elixirr-memory-bootstrap` | `php artisan working-memory:bootstrap {scope}` console command — reuses composer + emits historical compactions before consolidated snapshot |
| `meeting-synthesis`, `elixirr-meeting-notes`, `elixirr-meeting-writer` | `SynthesizeMeetingCompactionJob` triggered by meeting capture |
| `research-synthesis` | `SynthesizeResearchCompactionJob` triggered when scoped thoughts cross a research-cluster threshold |
| `elixirr-comms-normalizer` (role only, not Slack/Teams import) | `BuildScopeDigestJob` periodic clusterer over scoped thoughts |
| `elixirr-sync` | Retired — IdeaTub is canonical per the 2026-05-07 self-contained spec |
| All others | Out of scope; remain as agent-side skills |

## Architecture

### Compactions live in `working_memory_versions`

A compaction is a `working_memory_versions` row whose `build_type` belongs to a new compaction family. The canonical narrative snapshot (`build_type ∈ {consolidated, incremental}`) remains the only row eligible to be `latest_version_id`.

New `build_type` values:

- `compaction:meeting` — a synthesized meeting note (Summary / Decisions / Action Items / Risks / Open Questions).
- `compaction:weekly-digest` — a periodic clustered digest of recent scoped thoughts.
- `compaction:topic-digest` — an on-demand digest for a specific topic cluster.
- `compaction:research-synth` — a research-grade synthesis when the scope accumulates ≥ N research-tagged thoughts (configurable; default 8).

Every compaction row carries:

- `summary_markdown` — narrative prose for the compaction.
- `structured_sections_json` — section-shaped payload (matches the existing schema) so the composer can reuse content directly.
- `references_json` — citations resolvable in IdeaTub.
- `working_memory_inputs` rows linking the source thoughts that produced the compaction.

### Citation lineage: `working_memory_inputs` extension

Today `working_memory_inputs.thought_id` is `NOT NULL` FK → `thoughts`. Schema change:

- `thought_id` becomes nullable.
- Add nullable `source_version_id` FK → `working_memory_versions.id` (`nullOnDelete`).
- Add CHECK constraint: exactly one of (`thought_id`, `source_version_id`) is non-null.
- Existing unique index `(working_memory_version_id, thought_id)` remains; add a parallel unique index `(working_memory_version_id, source_version_id)`.
- Update `WorkingMemoryInput` model: nullable `thought_id`, new `sourceVersion()` BelongsTo.

This lets the canonical authored version cite compaction versions as inputs, while compactions themselves continue to cite raw thoughts.

### Compaction permalinks

To satisfy "every cited bullet resolves to an IdeaTub link", expose:

- Web route: `GET /memory/{scopeType}/{scopeKey}/compactions/{versionId}` — renders the compaction with sections, evidence list, and back-link to the canonical scope page. Authorized via the same scope guard as `/memory`.
- MCP read tool: `get_compaction(scope_type, scope_key, version_id)` returning the same payload shape as `get_working_memory` for a single version.
- Permalinks in `references_json` use `type: compaction`, `url: /memory/{scopeType}/{scopeKey}/compactions/{versionId}`, `label: <build_type> <date>`.

### Composer pipeline

```
[Raw Thoughts]
   │
   ├─► capture_meeting / meeting-shaped capture_plan
   │      └─► SynthesizeMeetingCompactionJob
   │              └─► writes build_type=compaction:meeting
   │
   ├─► continuous capture (any thought)
   │      └─► (no-op until scheduled)
   │
   └─► scheduled hourly / daily
          ├─► BuildScopeDigestJob → compaction:weekly-digest
          └─► SynthesizeResearchCompactionJob (when threshold tripped) → compaction:research-synth

[Compaction Versions] + [Recent Raw Thoughts (uncompacted)]
   │
   └─► WorkingMemoryEvidencePackBuilder (extended)
          - prefers compaction versions over raw thoughts
          - falls back to raw thoughts only when no compaction covers the window
          - emits per-source-type promotion hints
   │
   └─► WorkingMemoryAiAuthorService (model-backed)
          - one OpenRouterService::researchFromPrompt call per refresh
          - prompt encodes promotion rules per section
          - returns markdown + structured_sections + references_json
   │
   └─► WorkingMemoryOutputValidator (existing)
   │
   └─► persisted as build_type=consolidated|incremental → latest_version_id
```

### Model-call backend

Reuse `OpenRouterService::researchFromPrompt(string $prompt): string` (same entry used by `MeetingWorkflowRunner`). No new provider, no new abstraction.

A new prompt builder mirrors `MeetingPromptBuilder`:

- `WorkingMemoryComposerPromptBuilder` — for canonical narrative authoring.
- `MeetingCompactionPromptBuilder` — for `compaction:meeting`.
- `ScopeDigestPromptBuilder` — for `compaction:weekly-digest`.
- `TopicDigestPromptBuilder` — for `compaction:topic-digest`.
- `ResearchSynthesisPromptBuilder` — for `compaction:research-synth`.

All four return a single string prompt and are unit-testable in isolation.

## Promotion rules per source type

Encoded in the composer prompt and the evidence pack builder; mirrors `elixirr-memory-refresh` promotion rules:

| Source type | Default sections to update |
|---|---|
| `compaction:meeting` | Recent Changes, Next Actions, Risks / Blockers, Open Questions |
| `compaction:weekly-digest` | Latest Signals, Active Priorities, Recent Changes |
| `compaction:topic-digest` | Active Priorities, Open Questions, Latest Signals |
| `compaction:research-synth` | Open Questions, Risks / Blockers, Latest Signals |
| Raw thought (uncompacted, recent) | Latest Signals, Open Questions |
| Raw thought (recent, urgent keywords e.g. block/risk/issue/delay) | Risks / Blockers, Next Actions |

The composer is allowed to override these defaults when source content explicitly fits another section, but defaults shape the prompt.

## Triggers

### Event-driven

- On meeting-shaped thought capture — the path used by `capture_meeting` and meeting-aliased `capture_plan` (`doc_type: meeting`) — enqueue `SynthesizeMeetingCompactionJob` for that thought. The exact predicate reuses whatever flag the existing meeting capture path sets on the resulting `Thought` (e.g. `metadata.kind`, dedicated meeting tag, or `MeetingService` association); the trigger lives behind a single `isMeetingThought(Thought $t): bool` helper to keep capture conventions and trigger logic in sync.
- On `Thought` create or update affecting a scope, enqueue `RefreshWorkingMemoryIncremental` (existing) — the incremental refresh now reads compactions plus deltas.

### Scheduled

- `compactions:digest` — hourly; for each scope with new uncompacted thoughts, run `BuildScopeDigestJob` to maintain `compaction:weekly-digest`.
- `compactions:research` — daily; runs `SynthesizeResearchCompactionJob` for scopes where research-tagged thought count ≥ threshold and no fresh research compaction exists.
- `working-memory:consolidate` — existing; runs the canonical composer over the latest compactions and unconsolidated thoughts.

All scheduled jobs are idempotent and skip when no input has changed.

## Components

### New / modified services

- `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php` — replace deterministic body with a call into `WorkingMemoryComposerPromptBuilder` + `OpenRouterService::researchFromPrompt`; preserve return shape so downstream validator and persistence stay unchanged.
- `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php` — extend `selectSignals` to include compaction versions for the same scope, ordered by `created_at desc`, and to emit per-source-type promotion hints in the evidence pack.
- `app/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilder.php` — new.
- `app/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilder.php` — new.
- `app/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilder.php` — new.
- `app/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilder.php` — new.
- `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php` — new; persists a compaction version + its `working_memory_inputs` rows in one transaction.

### New jobs

- `app/Jobs/SynthesizeMeetingCompactionJob.php`
- `app/Jobs/BuildScopeDigestJob.php`
- `app/Jobs/SynthesizeResearchCompactionJob.php`

### New routes / controllers

- `app/Http/Controllers/MemoryCompactionController.php` — `show($scopeType, $scopeKey, $versionId)`.
- Web route added under the existing memory route group; uses the existing scope guard middleware.
- MCP method `get_compaction` added to `app/Http/Controllers/Api/McpController.php`.

### New console commands

- `working-memory:bootstrap {scope_type} {scope_key} [--user=]` — runs full historical compaction sweep then a consolidated authoring pass.
- `compactions:rebuild {scope_type} {scope_key} [--type=]` — manual recompute for a single compaction subtype.

## Schema changes

Single migration `2026_05_07_working_memory_compactions_extension.php`:

- `working_memory_inputs.thought_id`: drop `NOT NULL`.
- Add `working_memory_inputs.source_version_id` UUID nullable FK → `working_memory_versions.id` `nullOnDelete`.
- Add unique index `(working_memory_version_id, source_version_id)` named `working_memory_inputs_version_source_unique`.
- Add CHECK `(thought_id IS NOT NULL) <> (source_version_id IS NOT NULL)` (equivalent expression for SQLite + Postgres).
- No changes to `working_memories`; no changes to `working_memory_versions`. The new `build_type` values are application-level; `build_type` is a 32-char string and the longest new value (`compaction:weekly-digest`) is 24 chars.

Models updated:

- `WorkingMemoryInput` — `thought_id` nullable; add `sourceVersion()`; update `$fillable`.
- `WorkingMemoryVersion` — add helpers `isCompaction(): bool` and `compactionSubtype(): ?string`.

## MCP contract additions

- `get_working_memory` payload (existing) gains an optional `compactions[]` index inside `references[]`, distinguished by `type: "compaction"`.
- New `get_compaction(scope_type, scope_key, version_id)` returns the same shape as `get_working_memory` but for one compaction version.
- Both endpoints honor the same scope-isolation rules as the 2026-05-07 self-contained spec.

## Quality gates

Reuses `WorkingMemoryOutputValidator` plus two additions:

- A canonical narrative version's `references_json` must contain at least one `type: "compaction"` entry whenever any compaction version exists in the same scope and overlaps `source_window_start..source_window_end`. Missing → soft-failure (`authoring_status=fallback`, diagnostic reason `unused_compaction`).
- Compaction versions themselves must pass the existing per-section citation coverage threshold; otherwise the compaction is discarded (not persisted) and a diagnostic is emitted.

## Failure handling

- Model call failure on a compaction job → retry with backoff (3 attempts); on terminal failure, log and skip without writing a fallback compaction (canonical authoring still proceeds against existing compactions and raw thoughts).
- Model call failure on canonical authoring → retain last known good `latest_version_id`, mark `freshness_state=stale`, surface validation context (existing behavior).
- Evidence pack containing zero compactions and zero recent thoughts → composer is not called; deterministic empty-state fallback summary is written (existing behavior).

## Observability

Add to `build_diagnostics_json`:

- `compaction_inputs_count` (per build).
- `compaction_subtypes_used[]`.
- `raw_thought_inputs_count`.
- `compaction_coverage_ratio` (compaction-cited bullets / total cited bullets).

Add metrics:

- Compaction job success rate per subtype.
- Mean compaction count per scope per week.
- Mean `compaction_coverage_ratio` across canonical versions.
- Stale compaction ratio (compactions older than freshness threshold while their scope has new thoughts).

## Rollout plan

### Phase 1 — Schema + lineage

- Migration for `working_memory_inputs` extension.
- Model updates.
- No behavior change yet.

### Phase 2 — Composer

- Replace `WorkingMemoryAiAuthorService` deterministic body with model-backed composer.
- Existing scopes immediately render narrative output from raw thoughts only.
- Track `compaction_coverage_ratio` (will read 0 across the board).

### Phase 3 — Meeting compactions

- Ship `SynthesizeMeetingCompactionJob` and meeting-capture trigger.
- Evidence pack starts preferring meeting compactions.
- Add `/memory/.../compactions/{id}` route.

### Phase 4 — Digest compactions

- Ship `BuildScopeDigestJob` + scheduler entry.
- Active scopes accumulate weekly digests.

### Phase 5 — Research compactions

- Ship `SynthesizeResearchCompactionJob` with threshold trigger.
- Composer promotion rules favor research compactions for Open Questions / Risks.

### Phase 6 — Bootstrap

- `working-memory:bootstrap` console command for backfilling historical compactions on existing scopes.

## Acceptance criteria

- For an active scope, `summary_markdown` reads as continuous prose with concrete attribution, comparable to the dezeen reference.
- At least one compaction permalink appears in `references_json` for any scope with ≥ 1 compaction in the window.
- Every cited bullet resolves to an IdeaTub permalink (thought or compaction).
- `WorkingMemoryAiAuthorService` no longer contains the "deterministic authoring placeholder" comment or hard-coded section prefixes.
- Refresh time for a typical scope stays within an acceptable budget (target: ≤ 8 seconds end-to-end including model calls; revisit after Phase 2).
- All existing working-memory tests pass; new tests cover compaction lineage, evidence preference, and citation gates.

## Risks and mitigations

- **Risk:** model output drift produces inconsistent narrative shape.
  **Mitigation:** strict JSON schema in prompt, validator rejects shape violations, retry once with stricter wording before falling back.
- **Risk:** compaction count grows unbounded.
  **Mitigation:** per-scope retention policy (e.g. keep last 12 weekly digests, last 50 meeting compactions); soft-deleted compactions excluded from evidence selection.
- **Risk:** `compaction_coverage_ratio` quality gate is too strict before compactions accumulate.
  **Mitigation:** soft-warning only during Phases 3 and 4 while compactions ramp; promote to hard gate after observed quality stabilizes (target: two weeks after Phase 4 ships).
- **Risk:** model cost spikes from compaction jobs.
  **Mitigation:** scheduled jobs are idempotent and skip when input unchanged; per-scope rate limit on canonical authoring; `OpenRouterService` already supports per-call model selection.
- **Risk:** schema CHECK constraint differs across SQLite (dev) and Postgres (prod).
  **Mitigation:** application-layer guard in `WorkingMemoryInput` save path mirrors the CHECK; migration uses `DB::statement` with raw SQL conditional on driver.

## Implementation checklist

- [ ] Migration: nullable `thought_id`, add `source_version_id` FK + unique index + CHECK.
- [ ] Update `WorkingMemoryInput` model (fillable, casts, nullable thought, `sourceVersion()`).
- [ ] Update `WorkingMemoryVersion` model (`isCompaction`, `compactionSubtype`).
- [ ] Replace `WorkingMemoryAiAuthorService` body with model-backed composer.
- [ ] New `WorkingMemoryComposerPromptBuilder`.
- [ ] Extend `WorkingMemoryEvidencePackBuilder` to load compaction versions and emit promotion hints.
- [ ] New `SynthesizeMeetingCompactionJob` + `MeetingCompactionPromptBuilder` + `CompactionVersionWriter`.
- [ ] Hook meeting-capture path to enqueue meeting compaction job.
- [ ] New `BuildScopeDigestJob` + `ScopeDigestPromptBuilder` + scheduler entry.
- [ ] New `SynthesizeResearchCompactionJob` + `ResearchSynthesisPromptBuilder` + scheduler entry.
- [ ] New `MemoryCompactionController` + web route under memory group.
- [ ] New MCP `get_compaction` method.
- [ ] New `working-memory:bootstrap` and `compactions:rebuild` console commands.
- [ ] Extend `WorkingMemoryOutputValidator` with `unused_compaction` and per-compaction coverage checks.
- [ ] Add diagnostic fields to `build_diagnostics_json`.
- [ ] Tests: schema invariants, meeting compaction job, digest job, research job, evidence preference, citation gates, MCP payloads, web route auth.

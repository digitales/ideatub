# Global working memory fallback vs insights validated layout — Customer Support Investigation

**Date**: 2026-06-15  
**Status**: Resolved (manual global consolidate succeeded; ongoing ops model below)  
**Customer**: Ross (production `ideatub.com`)  
**Priority**: Medium  
**Reported By**: User — Memory page poorly formatted compared to `/memory/insights` and agent-upserted project memory  

## Issue Description

On production, **global** working memory (`/memory`) shows legacy heuristic output:

- Executive summary: “First-pass synthesis across 6301 thoughts highlights jira as the strongest signal.”
- Active threads / open questions contain truncated raw markdown (headings, checkboxes, broken link text).
- Details panel: `authoring_status: fallback`, `baseline_build_type: consolidated`, `source_label: —`.

By contrast, **`/memory/insights`** renders the eight-section layout (Current Focus, Active Priorities, …) with citation chips — same renderer used for agent `upsert_working_memory` (`authoring_status: external` or `validated`).

## Production evidence (2026-06-15)

Queried via MCP `get_working_memory` and `list_working_memory_versions`.

### Global (`scope_type=global`, `scope_key=global`)

| Field | Value |
|-------|--------|
| Canonical version | `019eab7b-f261-7229-a014-3a1c2fef905b` (2026-06-09) |
| `authoring_status` | `fallback` |
| `validation_error` | `Missing required sections: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes` |
| `build_diagnostics.reason_codes` | `["empty_required_section"]` |
| `structured_sections` | `[]` (empty — UI uses legacy markdown path) |
| `input_count` | 6301 |
| `source_label` | null (not agent-synced) |

**Version history:** All consolidated global versions since AI authoring was enabled (2026-05-21 onward) are `fallback`. None are `validated` or `external`. Earlier nightly builds show `authoring_status: disabled`.

### Insights (`scope_type=insights`, `scope_key=global`)

| Field | Value |
|-------|--------|
| Canonical version | `019e6c79-1bf4-7021-b24e-338149b8e7f1` (2026-05-28) |
| `authoring_status` | `validated` |
| `citation_coverage` | 100% |
| `structured_sections` | All 8 sections populated with citations |
| `input_count` | 71 (research-filtered corpus) |
| `build_diagnostics` | `required_items: 19`, `cited_items: 19`, `compaction_coverage_ratio: 0` |

### Project example (Lantern — external upsert works)

Project `019e3c05-3237-733c-a346-9bddc63d367b` canonical version `019ea95e-b4dc-73b5-a615-01f3709f6c9c`:

- `build_type: external`, `authoring_status: external`, `source_label: cursor-cross-analysis`

## Root cause analysis

### 1. UI renderer gate (not a CSS bug)

`memory/partials/structured_sections_content.blade.php` only renders the insights-style layout when:

- `structured_sections` is non-empty, **and**
- `authoring_status` is `validated`, `external`, or null.

With `fallback`, the page renders `summary_markdown` via `<x-safe-markdown>`. Legacy global markdown embeds truncated thought bodies as markdown links → broken display.

### 2. AI authoring is enabled but global compose fails

Production flags are on (see `support/2026-05-20-working-memory-incremental-non-json-timeout.md`). Global is **not** “authoring disabled.”

Failure chain (same as prior non-JSON incidents):

1. `WorkingMemoryAiAuthorService::authorFromEvidence()` runs for global consolidated build.
2. Composer output cannot be decoded / parsed into eight sections → `emptyOutput()` or empty `structured_sections`.
3. `WorkingMemoryOutputValidator` hard-fails with `empty_required_section`.
4. `WorkingMemoryBuilderService` sets `authoring_status: fallback`, clears `structured_sections`, runs `legacyPayloadAndSummary()` → `WorkingMemoryAssembler` over full thought set metadata.

`validation_error` on live global matches this exactly.

### 3. Why insights succeeds where global fails

| Factor | Global | Insights |
|--------|--------|----------|
| Input thoughts | 6301 (180-day window) | 71 (research-classified) |
| Evidence pack signals | Up to 60 recent signals | Smaller, coherent research set |
| AI compose result | Empty sections → fallback | Validated JSON/markdown → structured UI |
| Compactions used | 0 cited at compose time | 0 cited (compose still succeeded on raw research signals) |

Global’s problem is **compose failure at global scope scale/noise**, not missing compactions alone. Insights proves the pipeline works when the evidence set is smaller and research-focused.

### 4. Legacy assembler amplifies the mess

`WorkingMemoryAssembler` picks top tags and truncates thought `content` to 90 characters for threads/questions. Recent captures include long markdown research docs (Lantern vs Scanner cross-analysis, etc.) → headings and partial links in the UI.

Confidence score 100 is a heuristic (`25 + thoughtCount * 2.5 + tags * 8`), not quality.

## Customer impact

- Global memory is low-signal for humans and agents despite “FRESH” badge and 100 confidence.
- `/memory/insights` and project external memory mislead users into thinking the whole Memory product is working — only scoped/filtered paths are good.
- Manual “Refresh working memory” on global re-runs compose; prior runs republished fallback until manual consolidate succeeded (see Resolution).

## Resolution (2026-06-15)

Manual consolidated rebuild succeeded on production:

```bash
php artisan working-memory:consolidate --user=<id> --scope_type=global --scope_key=global --force
```

| Field | After manual run |
|-------|------------------|
| Canonical version | `019ecad0-2b00-732e-8a6f-286a98515e36` (2026-06-15T10:25:00+00:00) |
| `authoring_status` | `validated` |
| `validation_error` | null |
| `structured_sections` | All 8 sections populated |
| `input_count` | 6933 (180-day window) |
| `build_diagnostics` | `compaction_inputs_count: 20`, `compaction_subtypes_used: ["meeting"]`, `raw_thought_inputs_count: 60` |

Global `/memory` now renders the same eight-section layout as `/memory/insights`. Likely success factors: meeting compactions in the evidence pack, recent curated captures (including this support investigation), and milestone manual run vs flaky incremental refresh.

**First ever validated global consolidated version** in production version history (prior consolidated builds since 2026-05-21 were all `fallback`).

## Next steps (ongoing operating model)

### Priority order

1. **Milestone-sync global** — run `working-memory:consolidate` for `global/global` after meaningful checkpoints (end of week, bulk capture, support investigation). Do not rely on incremental refresh for global quality (incremental uses 7-day / 20-thought window).
2. **External-first for projects** — keep `elixirr-sync` → `upsert_working_memory` for client/project scopes. Nightly consolidate should use `--only-without-external`.
3. **Grow compactions** — `capture_meeting` after meeting notes so compose has summarized evidence, not only 60 raw signals from a 6k+ corpus.
4. **Monitor nightly job** — after scheduled consolidate, confirm `authoring_status` stays `validated`. If fallback returns, enable `WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW=true` for one run (PII; disable after).

### Consolidation window (180 days)

**Do not lower the deployment default as the first fix.** The prior failure was empty compose output, not window length alone. Evidence pack is capped at 60 signals regardless of window.

| Lever | Recommendation |
|-------|----------------|
| Personal trial | Set **90 days** in Settings → Working memory (per-user override). Re-run manual global consolidate and compare. |
| Deployment default | Keep **180** until 2–3 successful milestone consolidates at 90-day personal override. |
| Fleet-wide env | `WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS=90` only after personal trial proves value. |
| Project scopes | Window matters less when `external` upsert is canonical. |

Window still helps by biasing the 60 signals toward recent material and reducing legacy-fallback noise if compose fails again.

### Scope routing (decision-grade context)

| Need | Use |
|------|-----|
| Client/project work | Project scope + `upsert_working_memory` (`external`) |
| Research themes | `/memory/insights` (`validated`, research-filtered) |
| Cross-cutting “what matters” | Global + manual milestone consolidate |
| Legacy `fallback` | Treat as incident; avoid repeated refresh hoping for self-heal |

## Recommended operator actions

### Immediate

1. **Stop relying on global** for decision-grade context; use **project scopes + `upsert_working_memory`** (Elixirr `current.md`) or **`/memory/insights`** for research themes.
2. Check **History** on `/memory` — confirm no `validated` global version exists (production: none in 27 consolidated versions).
3. For Lantern and other projects: ensure `elixirr-sync` upserts use project UUID `scope_key` and eight-section `current.md` format.

### To fix global compose (engineering / ops)

1. Enable temporary logging: `WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW=true` — capture one global compose `raw_preview` (PII; disable after).
2. Force rebuild after mitigations:  
   `php artisan working-memory:consolidate --user=<id> --scope_type=global --scope_key=global --force`
3. If preview shows truncation: raise `WORKING_MEMORY_COMPOSER_MAX_TOKENS` / length retry.
4. If preview shows markdown-only output: confirm `WorkingMemoryComposerMarkdownParser` is deployed on production (fix from 2026-05-20 support doc).
5. Consider **lowering global consolidation window** or pre-building **global compactions** (weekly digests) so compose has summarized evidence instead of 60 raw signals from a 6k corpus.

### Product strategy (aligned with sync policy)

Per `docs/superpowers/plans/2026-05-28-working-memory-sync-policy.md`:

- Curated milestone sync, not frequent global refresh.
- External-first for project scopes.
- AI consolidation for scopes without fresh external memory — but **global may need a dedicated compaction strategy** before compose is viable at 6k+ inputs.

## Related issues

- `support/2026-05-20-working-memory-incremental-non-json-timeout.md` — same empty-section failure chain; markdown parser fix.
- `docs/superpowers/specs/2026-05-18-working-memory-hybrid-external-first-design.md` — external vs legacy regression.
- `resources/content/help/working-memory-corpus-sync.md` — corpus growth and AI authoring enablement.

## References

- `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` — fallback on empty sections.
- `app/Services/WorkingMemory/WorkingMemoryAssembler.php` — legacy 90-char truncation.
- `resources/views/memory/partials/structured_sections_content.blade.php` — UI gate.
- `app/Services/WorkingMemory/WorkingMemoryUpsertService.php` — external eight-section parse path.

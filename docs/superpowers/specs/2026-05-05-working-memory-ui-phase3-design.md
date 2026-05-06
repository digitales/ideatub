# Working Memory — Web UI, Insights, Phase 3 Overlay, and Configuration

## Relationship to core design

This document extends **[Working Memory in IdeaTub (Global + Project)](2026-05-05-working-memory-design.md)** with **user-facing surfaces**, **`/memory/insights`**, **Phase 3 read-time overlay UX**, and **consolidation-window configuration** (deployment default + optional per-user override).

Backend persistence (`working_memories`, versions, inputs), hybrid refresh jobs, and existing REST/MCP read APIs are assumed unless explicitly extended below.

## Goals

- Provide **first-class web UI** for working memory at **global** and **project** scopes.
- Add **`/memory/insights`**: corpus-wide **themes** and optional **model-assisted commentary**, emphasizing **research-oriented** thoughts without replacing scoped working memory.
- Implement **Phase 3 presentation**: **consolidated baseline** as the primary narrative; **incremental / recent deltas** in a **drawer or side panel** (stacked on narrow viewports).
- Surface **freshness** and **confidence** with **minimal default chrome** and a **Details** disclosure for full metadata.
- Make **consolidation window length** configurable: **deployment default** via env/config; **optional per-user override** in Settings.

## Non-goals (this spec)

- Replacing MCP/API-only workflows; those remain supported.
- Full editorial CMS for editing synthesized markdown in-app (corrections may be a later iteration).
- Perfect real-time push UX (polling or manual refresh on first ship is acceptable unless otherwise specified in the implementation plan).

## Resolved product decisions (brainstorming)

| Decision | Choice |
|----------|--------|
| Global memory discovery | **Hybrid:** top-level **Memory** nav plus a **compact home strip** linking into full Memory. |
| Corpus-wide topics / AI commentary | **Separate route:** **`/memory/insights`** (not only a section on the main Memory page). |
| Consolidation window | **Env default** + **nullable per-user override** in Settings. |
| Phase 3 overlay layout | **Main snapshot + drawer/side panel** for delta bullets (responsive stacking). |
| Freshness / confidence UI | **Minimal indicator** + **Details** expands full explanation. |

## Information architecture & routes

### Global

- **`GET /memory`** (name e.g. `memory.show`): primary **global** working memory page (authenticated).
- **`GET /memory/insights`** (name e.g. `memory.insights`): **Insights** — corpus-wide themes and commentary.
- **Home (`/` or existing idea index):** **strip** showing freshness/summary teaser and link to **`/memory`** (exact placement follows existing layout conventions).

### Project

- **`GET /projects/{project}`** (existing): add a **Working memory** module (card or tab) that loads **project** scope (`scope_type=project`, `scope_key` = project identifier per existing resolver rules).

### Navigation

- Add **Memory** to the primary authenticated nav next to existing entries (Stream, Projects, etc.), consistent with current Blade/layout patterns.

## Page UX — Memory (global & project)

### Header

- Title: **Working memory** + scope subtitle (**Global** or project name).
- **Freshness:** compact indicator (maps to `fresh` | `degraded` | `stale`).
- **Details** control: expands/collapses panel or modal with **confidence score**, **last refreshed**, effective **consolidation window** (days), **build type** of baseline when relevant, and short **traceability** hint (e.g. count of linked inputs or link to future “sources” view).
- Global only: secondary link **Insights** → **`/memory/insights`**.

### Body

- Render **latest consolidated** content as the **canonical** narrative (markdown from API or server-assembled DTO).
- Structured blocks (concepts, threads, questions, actions) may be rendered as sections if the API returns them as structured JSON in addition to markdown.

### Phase 3 overlay (drawer / panel)

- **Primary:** consolidated snapshot (stable).
- **Secondary:** **drawer or right-hand panel** listing **recent deltas** derived from incremental builds or assembler overlay fields (exact backend shape defined in implementation plan).
- Mobile: panel becomes **bottom sheet** or **toggleable section** below the main content; same information, no separate tab required by this spec.

### Empty / loading / error states

- **Loading:** skeleton for header + body + panel.
- **Empty corpus:** friendly copy + link to capture/stream.
- **Degraded / fallback:** if API indicates last-known-good fallback, show non-blocking banner + Details explanation.

## Page UX — Insights (`/memory/insights`)

- Distinct from scoped memory: focuses on **cross-corpus** patterns and **research-heavy** signals.
- **Sources (conceptual):** thoughts matching research-oriented criteria (e.g. metadata type, source tags, or explicit `research` doc types — align with existing `Thought` conventions in implementation).
- **Output:** sectioned markdown or cards: **Themes**, **Notable threads**, optional **Model commentary** (when keys/quota allow; align with prior “heuristic + optional model-assisted” decision in core spec).
- **States:** loading, partial/stale cache, “generating” if async job is used.

## Configuration — consolidation window

### Deployment default

- Continue to use **`config/working_memory.php`** with **`WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS`** (default **180**).
- Applies when the user has **no override**.

### Per-user override (optional)

- **Settings** UI: nullable integer field **“Working memory consolidation window (days)”** with validation (e.g. minimum **1**, maximum **3650** or a sane upper bound).
- **Persistence:** store on **`users`** as a nullable column (e.g. `working_memory_consolidation_window_days`) **or** an existing user-preferences JSON column if the codebase standard prefers that — implementation plan picks one pattern.
- **Resolution order:** if user override **is set**, builder uses it; else **`config('working_memory.consolidation_window_days')`**.
- Add a **Forced tags** control in the same settings area (chips or newline list): user-managed normalized tags that should always produce tag-scoped working memory during processing, even below normal thresholds.
- Forced-tag updates should trigger scoped refresh/consolidation for affected tag scopes so user intent is reflected quickly.

## Backend / API extensions (high level)

The implementation plan should specify concrete DTOs and routes. At minimum:

1. **Web controllers** for **`/memory`** and **`/memory/insights`** that authenticate and render Blade (or Inertia if that route is already standardized for logged-in idea surfaces).
2. **Assembler/API extensions** for Phase 3 overlay: response must expose enough structure for the UI to populate **drawer deltas** without scraping markdown (e.g. structured list of incremental highlights + timestamps).
3. **Insights pipeline:** persist Insights through the **same** `working_memories` / `working_memory_versions` / `working_memory_inputs` tables as other scopes (see **Resolved decisions** below). Optional queued jobs for heavy synthesis; short-lived HTTP cache may speed reads but **must not** replace versioned persistence as the source of truth.
4. **Settings:** form request validation + persistence for consolidation window override.

Existing **`GET /api/thoughts/working-memory`** remains the contract for agents; optional parallel **`Accept: application/json`** from web stack may reuse the same services.

## Feature flag & rollout

- Optional **`features.working_memory_ui`** (or reuse `config/features.php` patterns) to gate nav + routes during rollout.
- Insights may ship behind **`features.working_memory_insights`** if risk warrants split release.

## Testing

- **Feature tests:** auth gate on `/memory` and `/memory/insights`; project page includes module data when flag on.
- **Settings tests:** override persists and builder/consolidation respects resolution order (unit or feature as appropriate).
- **Overlay contract tests:** assembler/API returns fields required by drawer (once shaped).

## Success criteria

- Users can open **global** and **project** working memory in the app with clear freshness affordances and **Details**.
- Users can open **Insights** for corpus-wide themes without conflating it with a single project scope.
- Consolidation window is **tunable** at deploy time and **optionally** per user.
- Phase 3 UX matches **main snapshot + delta drawer** as specified.

## Resolved decisions (implementation alignment)

| Topic | Decision |
|-------|----------|
| **Insights persistence / versioning** | **Same tables as global and project memory:** `working_memories`, `working_memory_versions`, and `working_memory_inputs`. Insights are **not** stored in a separate snapshot table. Use a dedicated scope discriminator (e.g. `scope_type = insights`, `scope_key = global` per user—exact strings validated in code and API) so builders, freshness, and traceability behave like other scopes. |
| **Research eligibility (Insights sources)** | Treat a thought as research-oriented when it matches existing **Stream/type conventions**: `ThoughtTypeNavigation` classification to the research bucket **and/or** normalized `metadata.type === research`. No separate ad hoc allowlist unless product later tightens the rule. |

**Implementation:** Insights reads use **`WorkingMemoryAssembler::forScope(..., 'insights', 'global')`**, which persists versions via **`WorkingMemoryBuilderService`** on first materialization (same pattern as global/project).

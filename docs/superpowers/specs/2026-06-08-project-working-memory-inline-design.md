# Design: Project working memory inline on project detail page

**Status:** Approved (brainstorming)  
**Date:** 2026-06-08

## Relationship to other specs

- Builds on [`2026-05-05-working-memory-ui-phase3-design.md`](2026-05-05-working-memory-ui-phase3-design.md) (project memory routes and Memory IA).
- Reuses assembler payload and section partials from [`2026-05-05-working-memory-design.md`](2026-05-05-working-memory-design.md).
- Complements [`2026-05-06-working-memory-manual-refresh-surfaces-design.md`](2026-05-06-working-memory-manual-refresh-surfaces-design.md) (refresh form behavior).
- Does not replace [`2026-05-18-working-memory-hybrid-external-first-design.md`](2026-05-18-working-memory-hybrid-external-first-design.md) external-protected refresh rules.

## Problem

Project owners visit `/projects/{id}` to understand project context and contents. Working memory for that project lives on a separate page (`/projects/{id}/memory`) and the project detail page only shows a small sidebar stub (freshness line, refresh, link). Users must navigate away to read the synthesized eight-section memory that agents and humans rely on.

## Goal

Show **full project-scoped working memory** on the project detail page main column so the project page is the primary working surface. Keep `/projects/{id}/memory` for history, version browsing, and deep links.

## Resolved decisions (from brainstorming)

| Topic | Decision |
| --- | --- |
| Content scope | **Full inline** — all eight structured sections (Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes) |
| Placement | **Main column**, between pinned context thought (if any) and the Contents section |
| Metadata | **Collapsible Details panel** below the eight sections in the main column (not in the right sidebar) |
| Visual treatment | **Accent panel** — violet left accent border, subtle gradient header, star icon, nested white content well for sections; compact stat grid inside collapsed Details |
| Sidebar stub | **Remove** `projects/partials/working-memory-module` from the project show sidebar (refresh and history move into the accent panel header) |
| Dedicated memory page | **Keep** `/projects/{id}/memory` unchanged for history and full two-column detail view |
| First visit / no build | **No synchronous auto-build** on project page load; show empty accent panel with refresh CTA (matches prior sidebar behavior). Dedicated memory page may still auto-build via existing `forScope()` behavior. |
| Feature flag | Gated behind existing `FEATURE_WORKING_MEMORY_UI` / `config('features.working_memory_ui')` |

## Non-goals

- Removing or redirecting `/projects/{id}/memory`
- Lazy/async client-side fetch of working memory (server-render on page load)
- Changing global working memory index, MCP `get_working_memory`, or assembler build logic beyond a new optional read path
- Showing working memory on project index or shared-project views (this spec is authenticated project show only)

## Page layout

```
Project header (title, description, actions)

Main column                          Sidebar
─────────────────────────────────    ─────────────
[Pinned context thought — optional]

┌ Working memory (accent panel) ─┐   Add thought
│ Header: icon, title, freshness │   Import markdown
│ Refresh · History              │   (no WM stub)
│ ┌ white well: 8 sections ────┐ │
│ └────────────────────────────┘ │
│ ▸ Show details (collapsed)     │
└────────────────────────────────┘

Contents (member thoughts list)
```

## Data loading

### Controller

In `ProjectController@show`, when `config('features.working_memory_ui')` is true:

1. Call a new assembler method (see below) with `scope_type: project`, `scope_key: {project uuid}` (lowercase, matching existing normalization).
2. Pass the resulting payload (or `null`) to the view as `workingMemoryPayload`.

### New assembler read path

Add `WorkingMemoryAssembler::forScopeWhenBuilt(int $userId, string $scopeType, string $scopeKey): ?array`:

- Normalize scope via existing `WorkingMemoryScopeNormalizer`.
- Load `WorkingMemory` with `latestVersion` for user + scope.
- If no row or no authoritative version (`consolidated` or `external`, not superseded — same resolution as `payloadFromPersistedMemory`), return `null`.
- Otherwise return `payloadFromPersistedMemory($memory)` (or equivalent public wrapper).

**Do not** call `buildConsolidated()` from the project show path. Users trigger first build via the refresh form (existing queue/job flow).

### Payload fields used by the inline partial

Same as `memory/show` for project scope:

- `structured_sections`, `summary_markdown`, `authoring_status`
- `freshness_state`, `baseline_build_type`, `source_label`, `last_refreshed_at`
- `confidence_score`, `input_count`, `effective_consolidation_window_days`, `canonical_created_at`, `overlay_deltas`
- `references` (optional pills below sections)

Web `MemoryController@showProject` uses `WorkingMemoryAssembler::forScope()` directly (no `ProjectPinnedContextPayload` merge). Match that web path for v1; MCP merge is out of scope unless product later requires pinned-context overlay on the project page.

## UI components

### New partial

`resources/views/projects/partials/working-memory-inline.blade.php`

Accent panel markup (approved UI exploration option **Accent panel**):

- Outer: `rounded-2xl border border-memory-violet/20 border-l-[4px] border-l-memory-violet bg-gradient-to-br from-memory-violet/[0.06] via-white/95 to-white` with light shadow
- Header row: star icon in violet well, "Working memory" title, inline freshness + source summary line, Refresh (shared refresh form) and History link (`projects.memory.versions`)
- Content well: `rounded-xl bg-white/85 ring-1` containing `@include('memory.partials.structured_sections_content', …)` with uppercase violet section headings (as in approved mock)
- References row below well when `references` non-empty (reuse memory/show pill pattern)
- Collapsed `<details>` "Show details" with compact 4-stat grid (confidence, inputs, source, build type); expanded state includes full metadata from `memory.partials.details_card` or equivalent fields

### Project show integration

Replace:

```blade
@includeWhen(config('features.working_memory_ui'), 'projects.partials.working-memory-inline-preview')
```

With:

```blade
@includeWhen(config('features.working_memory_ui'), 'projects.partials.working-memory-inline', [
    'project' => $project,
    'workingMemoryPayload' => $workingMemoryPayload ?? null,
])
```

Remove sidebar include of `working-memory-module`.

### Empty state

When `workingMemoryPayload` is `null`:

- Render the same accent panel shell with muted copy: "Working memory not built yet for this project."
- Show `@include('components.working-memory-refresh-form')` with project refresh action
- Optional link: "Open working memory page" → `projects.memory.show` (triggers build on that route if user prefers)

### External-protected memory

Reuse the same conditional logic as `memory/show.blade.php`:

- When `baseline_build_type === 'external'` and within `external_protect_days`, show agent-sync messaging and force-refresh affordance when AI authoring enabled
- "Synced from agent" badge in header when `baseline_build_type === 'external'`

### Cleanup (post-implementation)

Remove design-exploration artifacts:

- Delete `projects/partials/working-memory-inline-preview.blade.php`
- Remove conditional `ui-picker.js` injection from `layouts/idea.blade.php` (if only used for this exploration)
- Remove all `data-uidotsh-pick` / `data-uidotsh-option` attributes

## Routes and navigation

No new routes. Existing routes used:

| Action | Route |
| --- | --- |
| Refresh | `working-memory.refresh.project` |
| History | `projects.memory.versions` |
| Full memory page (optional) | `projects.memory.show` |

## Testing

### Update

- `tests/Feature/ProjectMemoryModuleTest.php` — assert refresh form appears in main-column working memory block (not sidebar); assert sidebar stub absent.

### Add

- **Built memory:** Seed project-scoped `WorkingMemory` + version with `structured_sections_json`; GET `projects.show` asserts section headings (e.g. "Current Focus") and accent panel title.
- **Not built:** GET `projects.show` with no working memory row asserts empty-state copy and refresh form; assert no `buildConsolidated` side effect during request (no new version created).
- **Feature flag off:** With `working_memory_ui` false, assert neither inline block nor sidebar stub appears.

## Error handling

- Assembler returns `null` → empty state (not an error).
- Partial receives malformed payload → fall back to `summary_markdown` via existing `structured_sections_content` fallback (same as memory show).
- Project show must not 500 if working memory tables empty.

## Implementation notes

- Prefer extracting shared header/actions (freshness badges, refresh, history) into a small partial if `memory/show` and inline partial duplicate logic; keep scope minimal — inline panel first, DRY second pass if duplication exceeds ~30 lines.
- Section heading styling in the accent well uses uppercase violet labels; ensure `prose-memory-list-headings` and Tailwind prose overrides match the approved mock without breaking the dedicated memory page prose.

## Success criteria

1. Authenticated project owner sees full eight-section working memory on `/projects/{id}` when memory is built.
2. Details metadata available via collapsed panel below sections.
3. Refresh and history accessible from accent panel header.
4. Sidebar working memory stub removed.
5. Project page load does not trigger synchronous memory build when none exists.
6. `/projects/{id}/memory` continues to work for history and deep links.

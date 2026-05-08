# Design: Working memory index (all scopes)

**Status:** Approved — implemented (`feature/working-memory-index`)  
**Date:** 2026-05-08

## Relationship to other specs

- Builds on [`2026-05-05-working-memory-ui-phase3-design.md`](2026-05-05-working-memory-ui-phase3-design.md) (routes and Memory IA).
- Complements [`2026-05-07-working-memory-compactions-design.md`](2026-05-07-working-memory-compactions-design.md) (compaction detail URLs unchanged).
- Implementation may add `working_memories.build_started_at` alongside existing consolidation/refresh jobs.

## Goal

Give users a single page listing **every scope that already has persisted working memory**, each linking to that scope’s **existing** working memory detail view. Surface **in-flight builds** and **non-validated authoring outcomes** without mislabeling terminal fallback states as “loading.”

## Resolved decisions (from brainstorming)

| Topic | Decision |
| --- | --- |
| Which rows appear | **Only** scopes with an existing `working_memories` row (Option A). No placeholder rows for projects/tags that have never been built. |
| “In progress” | **C:** Combine **active build** (job lifecycle) with **finished-but-not-validated** authoring (`fallback` on latest version). Active build takes precedence in the UI. |
| Layout | **C:** Sections **Global → Insights → Projects → Tags**; within each section, sort by **`last_refreshed_at` descending**, nulls last. Omit a section heading block if it has zero rows. |
| Detail targets | Reuse existing routes: `memory.show`, `memory.insights`, `projects.memory.show`, `memory.tag.show`. No replacement of `/memory` as the global narrative page. |

## Non-goals

- Listing projects or tags that do not yet have a `working_memories` row.
- Replacing Insights UX or changing how insights content is generated.
- Replacing compaction detail (`/memory/{scopeType}/{scopeKey}/compactions/{versionId}`).
- Real-time push updates (SSE/WebSockets); optional polite refresh or polling is acceptable later.

## Routes and navigation

- **New route:** `GET /memory/scopes` (name: **`memory.scopes.index`**, exact name may adjust but must stay stable for nav).
- **Middleware:** Same as other Memory UI routes: `auth` + `working.memory.ui` (and feature flag via existing config).
- **Navigation:** Add **“All memories”** (or equivalent label) from primary Memory entry points: authenticated nav and/or links from `/memory` and `/memory/insights`, consistent with Blade layout patterns.

## Page structure

1. Page title and short description (aligned with existing Memory styling).
2. For each non-empty group in order: **Global**, **Insights**, **Projects**, **Tags**.
3. Each group contains a list of rows (cards or list rows) with title, optional freshness line, badges, and link.

## Data loading

- Query: `WorkingMemory::where('user_id', $userId)->with('latestVersion')`.
- **Projects:** Eager-load `Project` models for all `scope_type === 'project'` keys in one query; if a project is missing, still show the row with a clear title fallback (e.g. **Unavailable project**) and follow orphaned-row linking rules below.
- **Tags:** Resolve display labels via the same canonical tag resolution path used elsewhere (e.g. `UserCanonicalTagResolver`); fallback to a readable representation of `scope_key`.

## Row titles and links

| `scope_type` | `scope_key` | Title | Detail URL |
| --- | --- | --- | --- |
| `global` | `global` | Global | `memory.show` |
| `insights` | `global` | Insights | `memory.insights` |
| `project` | project id | Project title | `projects.memory.show` when project exists |
| `tag` | normalized tag slug | Canonical tag label | `memory.tag.show` with `tag` query |

**Orphaned project row** (working memory exists but `Project` missing): Do not link to a broken project URL; show disabled row or link to a safe hub (e.g. projects index). Exact behavior is implementation detail; must not 500 the index page.

## Badges and lifecycle

### Why two mechanisms

The consolidated/incremental builder runs authoring **synchronously inside the job** and persists the version **only when finished**. There is therefore **no** durable `authoring_status` row meaning “composer mid-flight” today. **Active authoring** is covered by the same window as **active build**.

### `working_memories.build_started_at`

- **Migration:** nullable `datetime` on `working_memories`.
- **Set** when consolidate/incremental work **starts** for that scope (job entry after scope resolution), not only on HTTP acknowledgement of a refresh button.
- **Clear** when a new version is successfully committed as `latest_version_id`, or when the job **terminally fails** after retries so the UI does not stay “Updating” forever.

### Badge rules

| Condition | Badge |
| --- | --- |
| `build_started_at` not null | **Updating** (or single agreed term app-wide) |
| `build_started_at` null **and** `latest_version.authoring_status === 'fallback'` | **Fallback** or **Needs attention** (distinct from Updating) |
| `authoring_status === 'validated'` or authoring disabled with no fallback | No authoring badge |

**Precedence:** Updating overrides Fallback while a build is active.

**Optional:** surface `authoring_status === 'disabled'` with a subtle label — **default omit** on the index to reduce noise.

## Error handling and edge cases

- **Empty index:** Show guidance when the user has zero `working_memories` rows: direct them to global working memory and/or refresh flows already present in the product.
- **Index page errors:** Project/tag resolution failures must degrade to fallbacks without breaking the whole page.

## Testing

- **Feature:** Authenticated user with mixed scopes sees correct grouping, order within groups, and correct URLs.
- **Feature:** Updating badge when `build_started_at` is set; cleared when cleared.
- **Feature:** Fallback badge when latest version is `fallback` and not updating.
- **Unit (optional):** Small pure helper for badge resolution given memory + latest version.

## Accessibility

- Badges use text labels, not color alone; row links have clear accessible names including scope title.

## Future enhancements

- Performance: batch canonical tag resolution and DB-side tag extraction for `/memory/scopes` — see [`2026-05-08-working-memory-scopes-performance-design.md`](2026-05-08-working-memory-scopes-performance-design.md).
- Separate **Queued** vs **Running** if queue latency matters.
- Poll or live region for automatic badge clearance after background completion.

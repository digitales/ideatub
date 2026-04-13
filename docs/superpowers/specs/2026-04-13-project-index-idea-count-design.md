# IdeaTub — Project index: top-level idea count

**Date:** 2026-04-13  
**Status:** Approved for implementation planning  
**Depends on:** Projects + `project_thought` pivot ([2026-04-13-projects-and-thought-links-design.md](./2026-04-13-projects-and-thought-links-design.md))

## Goal

On the authenticated **Projects** index (`GET /projects`, `projects.index`), show for each project how many **top-level ideas** (thoughts with `parent_id` null) are linked to that project via `project_thought`. Nested/reply thoughts linked to the same project do **not** increase this count.

## Non-goals

- No schema or migration changes.
- No change to project show, graph, or share surfaces in this spec.
- No separate count for “all members including replies” on the index (only the top-level count defined here).

## Behaviour

| Case | Display |
|------|---------|
| 0 top-level linked thoughts | `0 ideas` |
| 1 | `1 idea` |
| N > 1 | `N ideas` |

Copy uses **idea / ideas** on this line to match the product wording for the index feature (distinct from other screens that say “thought”).

## Backend

- **Controller:** `App\Http\Controllers\ProjectController::index`.
- **Query:** Keep existing filters and ordering (`user_id`, `orderByDesc('updated_at')`).
- **Count:** Add Eloquent `withCount` on the `thoughts` relationship with:
  - Constraint: `whereNull` on **`thoughts.parent_id`** (qualified table name to avoid ambiguity with the pivot).
  - Attribute alias: e.g. `top_level_ideas_count` (do not overload a generic `thoughts_count` unless it is explicitly aliased and documented).
- **Efficiency:** Single query pattern with subselect counts; avoid N+1 and avoid eager-loading full thought collections for the index.

## UI

- **View:** `resources/views/projects/index.blade.php`.
- **Placement:** On each project card, show the count in the same muted secondary style as the existing “Updated …” line — e.g. **`{n} idea(s) · Updated {relative}`** on one line, with correct singular/plural.
- **Empty project list:** Unchanged (no per-row count).

## Data integrity

`project_thought.thought_id` is `constrained('thoughts')->cascadeOnDelete()`. When a thought is deleted, pivot rows disappear; the count reflects current DB state without extra application logic.

## Testing

- **Feature tests** (extend `tests/Feature/ProjectCrudTest.php` or add a focused test class):
  - Project with two linked **root** thoughts → count **2** on index.
  - Same project also links a **child** thought (`parent_id` set) → count remains **2**.
  - Project with no linked thoughts → **0 ideas** visible for that row.
- Assert as the authenticated project owner; follow existing auth patterns in `ProjectCrudTest`.

## Out of scope for this document

Implementation plan and task breakdown: follow **writing-plans** after this spec is reviewed.

# IdeaTub — Project members: most recently updated first

**Date:** 2026-05-26  
**Status:** Approved for implementation planning  
**Depends on:** [2026-04-13-projects-and-thought-links-design.md](./2026-04-13-projects-and-thought-links-design.md)

## Goal

On user-facing project member lists, show thoughts with the **most recently updated at the top** and the **oldest at the bottom**. “Recent” means `thoughts.updated_at`, not pivot insertion order.

## Non-goals

- No change to project graph node ordering (stays on pivot `sort_order`).
- No manual drag-and-drop reorder UI in this spec (future work may use `sort_order`).
- No schema or migration changes.
- No change to how members are added or removed (`ProjectMembershipService` unchanged).

## Behaviour

**Ordering rule:** `thoughts.updated_at DESC`, then `thoughts.id ASC` as a stable tiebreaker.

When a thought is edited anywhere in the app (detail page, capture, import, etc.), its `updated_at` changes; on the next page load it moves toward the top of member lists. This is intentional.

| Surface | Route / view | Change |
|---------|--------------|--------|
| Project show — Members list | `GET /projects/{project}`, `projects.show` | Display order |
| Shared project hub — Items list | `GET /shared/projects/{token}`, `shared-projects.hub` | Display order |
| Shared project — Read all | `GET /shared/projects/{token}/read`, `shared-projects.read` | Display order |
| Project graph | `GET /projects/{project}/graph/data` | Unchanged (`sort_order`) |

The **Add thought** dropdown on the project show page already orders candidates by `updated_at` desc; member list order will now match that mental model.

## Approach

**Recommended pattern (approach B):** a shared display-order helper on `Project`, used only at list surfaces. The default `Project::thoughts()` relationship keeps `orderByPivot('sort_order')` so graph, membership service, and future manual reorder continue to work.

Do **not** change the default relationship order globally — that would affect graph and internal callers that assume insertion order.

## Backend

### Central helper

Add a method on `App\Models\Project` that applies display ordering to a `thoughts()` query builder, for example:

```php
public function orderMembersForDisplay($query): void
{
    $query->orderByDesc('thoughts.updated_at')
        ->orderBy('thoughts.id');
}
```

Use qualified column names (`thoughts.updated_at`, `thoughts.id`) to avoid ambiguity with the pivot table.

### Call sites

| File | Method | Change |
|------|--------|--------|
| `App\Http\Controllers\ProjectController` | `show` | Replace `orderByPivot('sort_order')` eager-load constraint with display order helper |
| `App\Http\Controllers\SharedProjectViewController` | `renderHub` | Same |
| `App\Http\Controllers\SharedProjectViewController` | `renderReadAll` | Replace explicit `orderByPivot('sort_order')` query with display order helper |

### Unchanged

- `Project::thoughts()` relationship definition (`sort_order` default).
- `ProjectMembershipService` (`addThought`, `removeThought`, `reorder`).
- Blade templates (`projects/show.blade.php`, `shared_projects/hub.blade.php`, `shared_projects/read_all.blade.php`) — no markup changes.

## Testing

### New feature tests

Add tests (extend `tests/Feature/ProjectCrudTest.php` and `tests/Feature/SharedProjectViewTest.php`, or a focused test class) that:

1. **Project show:** Create a project with two member thoughts where thought B has a newer `updated_at` than thought A (regardless of pivot `sort_order`). `GET projects.show` response HTML must show B’s content before A’s.
2. **Shared hub:** Same fixture; `GET shared-projects.hub` shows B before A.
3. **Read all:** Same fixture; `GET shared-projects.read` renders blocks in B-then-A order.

Use `Carbon::setTestNow()` or explicit `updated_at` on factory/create where needed for deterministic timestamps.

### Existing tests

- `project attaches thoughts ordered by pivot sort_order` in `tests/Feature/ProjectModelRelationsTest.php` — **keep unchanged**; it validates the relationship default, not display surfaces.
- `ProjectMembershipServiceTest::test_reorder_updates_pivot_order` — **keep unchanged**.

## Out of scope for this document

Implementation plan and task breakdown: follow **writing-plans** after this spec is reviewed.

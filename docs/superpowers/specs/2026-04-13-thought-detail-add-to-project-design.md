# Thought detail — Add to project (header control + inline new project)

**Date:** 2026-04-13  
**Status:** Approved for implementation  
**Depends on:** `docs/superpowers/specs/2026-04-13-projects-and-thought-links-design.md` (projects + pivot model)

## Goal

Improve the idea thought detail page so users can add the thought to a project from a prominent control in the **Thought detail** header, optionally **creating a new project in the same flow**, without duplicating the attach form in the Projects panel.

## UX

### Placement

- In `thought_detail_header`, add an **Add to project** control near the top of the **Thought detail** card (aligned with existing header spacing and type badge row as implementation sees fit).
- The control **reveals** the add-to-project form (closed by default): use a disclosure pattern (`<details>` or button + region) with **keyboard support** (`aria-expanded`, focusable trigger).

### Form behavior

- **Project** `<select>` lists every owned project the thought is **not** already in, plus **New project…**.
- When **New project…** is selected, show:
  - **Title** — required
  - **Description** — optional  
  Limits match `StoreProjectRequest`: title `max:255`, description nullable `max:65535`.
- Primary submit label: **Add to project**.
- If the thought is in **all** projects, the select should still offer **New project…** only (or equivalent) so the flow remains useful.
- If the user has **no** projects yet, behavior matches the “only new project” case.

### Projects panel cleanup

- In `thought_detail_projects_and_links`, **remove** the inline `<select>` + **Add** under Projects.
- That section keeps: heading, project chips with links, remove (×), then **Linked thoughts** unchanged.

## Backend

### Endpoint

- Extend **`POST`** `thoughts.projects.store` (`ThoughtProjectController@store`). **No new route.**

### Request modes (mutually exclusive)

**Mode A — existing project**

- `project_id`: required, `uuid`, exists on `projects` for `user_id` = current user.
- Authorize `update` on the resolved `Project` (as today).
- Reject if the thought is **already** a member of that project (validation message, not a DB constraint blow-up).

**Mode B — new project**

- `project_id` absent or empty.
- `new_project_title`: required, `string`, `max:255`.
- `new_project_description`: optional, `nullable`, `string`, `max:65535`.
- `$this->authorize('create', Project::class)`.

### Processing

- Wrap in `DB::transaction`:
  1. If mode B: create `Project` with `user_id`, `title`, `description`.
  2. `ProjectMembershipService::addThought($project, $thought)` (same invariants as today: same user, etc.).

### Response

- `redirect()->back()` with flash success: **“Added to project.”** when attaching to an existing project; **“Project created and thought added.”** when a new project was created in the same request.

### Validation errors

- Standard field errors for `project_id`, `new_project_title`, `new_project_description`.
- If both modes are partially satisfied, fail with explicit validation (no silent preference).

## Demo mode

- Pass the same **`editable`** flag used for the thought detail header (`! DemoMode::enabled()` from the show action) into the projects/links partial.
- When `editable` is **false**:
  - Do **not** render the Add to project disclosure/form in the header.
  - Do **not** render remove-from-project (×) actions on chips (read-only membership display for demo).

## Testing (Pest feature tests)

1. Attach thought to an **existing** project — success, flash, pivot row exists.
2. **Inline create** — new project persisted with title/description, thought attached.
3. **Validation** — neither mode, conflicting fields, invalid `project_id`, title too long.
4. **Already a member** — rejected with validation error.
5. **Authorization** — cannot attach using another user’s project or thought (follow existing test patterns).

## Out of scope

- Changing project graph, shares, or link UI.
- Replacing the full **Projects → Create** page (remains for users who prefer standalone creation).

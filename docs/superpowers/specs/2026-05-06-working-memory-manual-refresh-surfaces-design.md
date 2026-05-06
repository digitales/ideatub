# Working Memory Manual Refresh Buttons for Key Surfaces

## Overview

Add explicit **Refresh working memory** buttons on key content surfaces so users can trigger a **consolidated rebuild** on demand for the current scope, instead of waiting for automatic/on-read refresh behavior.

Initial target surfaces:

- Project page module (`projects/{project}`)
- Project memory page (`projects/{project}/memory`)
- Global memory page (`/memory`)
- Tag-context surfaces (where a specific tag filter is active)

## Goals

- Provide a clear manual rebuild action where users inspect scope-specific memory.
- Use one consistent action label and feedback pattern across surfaces.
- Keep refresh semantics explicit: manual click always queues a **consolidated** rebuild.
- Reuse existing queue/job infrastructure (`ConsolidateWorkingMemory`) with strict scope validation.

## Non-Goals

- Running rebuild synchronously in request/response.
- Adding client-specific scope in this iteration (no dedicated client scope route exists yet).
- Replacing existing automatic incremental refresh behavior.

## Resolved Decisions

| Topic | Decision |
|-------|----------|
| Build type on click | Always `consolidated` |
| Surface set | Project module + project memory page + global memory page + tag-context views |
| Backend shape | Shared dispatch logic + thin surface-specific route handlers |
| Existing settings button | Keep existing forced-tags settings behavior unchanged |

## UX Contract

### Action label and behavior

- Button label: **Refresh working memory**
- Optional helper text: **Queues a consolidated rebuild.**
- Click behavior: queue job, return immediately, show flash status message.

### Feedback copy

- Global: `Queued consolidated rebuild for global working memory.`
- Project: `Queued consolidated rebuild for project working memory.`
- Tag: `Queued consolidated rebuild for tag working memory.`
- Error examples:
  - invalid tag context
  - unauthorized project
  - missing scope input

## Scope Mapping

### Global memory page (`/memory`)

- Queue with:
  - `scope_type = global`
  - `scope_key = global`

### Project surfaces (`projects/{project}`, `projects/{project}/memory`)

- Queue with:
  - `scope_type = project`
  - `scope_key = {project id}` (normalized by existing scope normalizer)

### Tag-context surfaces

- Show button only when active tag context is present.
- Queue with:
  - `scope_type = tag`
  - `scope_key = {normalized active tag}`

## Backend Design

### Routing and controller shape

Add explicit POST actions for manual refresh:

- Global refresh endpoint
- Project refresh endpoint
- Tag refresh endpoint

Each handler should:

1. Resolve user and scope input.
2. Authorize access for the scope.
3. Normalize scope key via existing `WorkingMemoryScopeNormalizer`.
4. Dispatch `ConsolidateWorkingMemory` with `(userId, scopeType, scopeKey)`.
5. Redirect back with scope-specific success/error flash.

### Shared dispatch helper/service

Use shared logic to avoid duplication:

- one helper method or dedicated service for dispatch + normalization
- thin controller methods per surface

## Authorization and Validation

- `global`: authenticated user only, fixed `scope_key=global`.
- `project`: must pass project policy (`view`).
- `tag`: validate tag exists in request context, normalize/trim/lowercase, reject empty/invalid.
- Do not allow arbitrary `scope_type` injection from UI forms.

## UI Placement

- `projects/partials/working-memory-module.blade.php`: add refresh button next to existing memory link.
- `memory/show.blade.php`: add refresh button in header area for global and project scope variants.
- Tag-context view(s): place button near active tag summary/filter controls.

## Testing Strategy

### Feature tests

- Global refresh POST queues `ConsolidateWorkingMemory(global, global)`.
- Project refresh POST queues `ConsolidateWorkingMemory(project, {project-id})`.
- Project refresh forbidden for non-owner/non-authorized user.
- Tag refresh POST queues normalized tag scope.
- Tag refresh rejects missing/invalid tag.

### View tests

- Buttons render on:
  - project module
  - project memory page
  - global memory page
  - tag-context surface when tag is active
- Tag button does not render when no active tag context exists.

### Regression tests

- Existing settings forced-tags build action remains unchanged and passing.
- Existing automatic refresh flows continue to work (no behavior regression).

## Rollout

- Ship under existing working-memory UI feature flags (no new flag required).
- No schema migration required.
- Low-risk additive change: new POST handlers + UI controls + queue dispatch only.

## Risks and Mitigations

- Mis-scoped refresh requests:
  - mitigate with strict surface-specific handlers + scope normalization.
- Duplicate queueing from rapid clicks:
  - mitigate with client-side disabled state during submit and idempotent operational tolerance.
- User confusion about immediate result:
  - mitigate with explicit helper text and success message stating queued rebuild.

## Success Criteria

- Users can trigger manual consolidated rebuild from global, project, and tag-context surfaces.
- Correct scope job is queued for each surface.
- Unauthorized or invalid refresh attempts fail safely with clear feedback.
- Existing working-memory settings and automatic refresh behavior remain stable.

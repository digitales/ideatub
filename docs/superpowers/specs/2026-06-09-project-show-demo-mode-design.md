# Project Show Demo Mode Obfuscation Design

**Date:** 2026-06-09  
**Status:** Ready for review  
**Scope:** Extend per-session demo mode to `projects.show` only — obfuscate sensitive narrative text at render time via presenters, hide mutating sidebar controls and the working memory block when demo mode is active.

## Overview

Demo mode already obfuscates sensitive text on thought detail, idea index, stream, and related surfaces using session-backed `DemoMode`, deterministic `DemoObfuscator`, and presenter boundaries. **Project show** (`projects.show`) is not yet covered and renders raw `Project` and `Thought` fields directly.

Presenters walking through a project during a live demo would otherwise expose client names, project descriptions, pinned context bodies, and member thought titles/excerpts even when demo mode is enabled elsewhere.

This design adds render-time obfuscation to **project show only**, without mutating stored data. It follows the existing presenter-driven pattern from `docs/superpowers/specs/2026-03-31-demo-mode-obfuscation-design.md`.

## Goals

- Obfuscate sensitive narrative text on `projects.show` when demo mode is active
- Preserve real structure: member count, dates, type labels, navigation links, sort order, empty states
- Hide controls that could leak raw text or mutate data during a demo (sidebar forms, pin/remove, import)
- Hide the working memory inline block on project show in demo mode (avoids leaking structured memory text without a separate obfuscation pass)
- Fail closed to neutral placeholders on obfuscation errors
- Add focused tests; update the v1 boundary checklist

## Non-Goals

- `projects.index`, `projects.graph`, `projects.edit`, `projects.create`
- `/api/projects` JSON obfuscation
- Working memory obfuscation (block is hidden instead)
- Markdown import modal client-side preview obfuscation (import UI hidden in demo mode)
- Shared / public project pages
- Response-rewriting middleware or model-level changes

## Current State

`ProjectController::show()` passes raw models to `resources/views/projects/show.blade.php`:

- `$project->title` and `$project->description` in header and page title
- `$contextThought->content` via `projects.partials.context-thought` (full markdown)
- Member rows via `ProjectMemberThoughtPresenter`, which derives `title()` and `excerpt()` from raw `Thought.content` without demo obfuscation
- Sidebar "Add thought" `<select>` options use `Str::limit($t->content, 80)`
- Working memory inline partial renders structured section text when `features.working_memory_ui` is on
- Markdown import drop zone uses Alpine with client-side file preview

`ProjectMemberThoughtPresenter` exists and is unit-tested for title/excerpt derivation but does not use `ObfuscatesDemoText`.

## Approaches Considered

### 1. Presenter extension (recommended)

Add `ProjectShowPresenter` and `ProjectContextThoughtPresenter`; extend `ProjectMemberThoughtPresenter` with `ObfuscatesDemoText`. Swap Blade to read presenter output. Gate destructive/sidebar UI when `DemoMode` is enabled.

Aligns with existing thought/stream/idea demo coverage. Explicit, testable, fail-closed.

### 2. Blade helper only

Inline `@demoText($project->title, 'project_title')` at each sensitive output.

Smaller upfront structure but easy to miss surfaces (page title, markdown component input, derived title/excerpt paths). Harder to maintain as project show grows.

### 3. Hide all main content in demo mode

Replace project body with a single placeholder.

Safest but unsuitable for demos — loses member list layout and context affordances.

## Recommendation

Use **presenter extension** plus **UI gating** for sidebar and working memory. No model changes.

## Sensitive Fields (project show)

| Surface | Raw source | Presenter / handling | Field context |
|---------|-----------|----------------------|---------------|
| Page `<title>` | `$project->title` | `ProjectShowPresenter::pageTitle()` | `project_title` |
| H1 title | `$project->title` | `ProjectShowPresenter::pageTitle()` | `project_title` |
| Description | `$project->description` | `ProjectShowPresenter::descriptionMarkdown()` | `project_description` |
| Pinned context (markdown) | `$contextThought->content` | `ProjectContextThoughtPresenter::markdown()` | `thought_content` |
| Pinned context (microsite label) | `MicrositePageLabel::forThought()` | `ProjectContextThoughtPresenter::displayLabel()` | `thought_content` |
| Member row title | derived from thought content | `ProjectMemberThoughtPresenter::title()` after derivation | `thought_content` |
| Member row excerpt | derived from thought content | `ProjectMemberThoughtPresenter::excerpt()` after derivation | `thought_content` |

**Remains real:** member/idea counts, `updated_at` relative dates, type labels, thought URLs, project UUID routes, static UI copy, empty-state messages.

**Hidden in demo mode (not obfuscated):**

- Working memory inline partial (`projects.partials.working-memory-inline`)
- "Add thought" sidebar form and thought picker
- Markdown import drop zone and modal
- Context "Unpin" control (`editable => false` on context partial)
- Member row "Pin as context" and "Remove" actions

Rationale: hiding avoids leaking raw `$t->content` in `<option>` labels and structured working memory text without expanding slice scope.

## Architecture

```
ProjectController::show()  →  same models as today

projects/show.blade.php
  ├─ ProjectShowPresenter::fromProject($project)
  │     pageTitle(), descriptionMarkdown()
  ├─ @include context-thought (obfuscated markdown via presenter)
  ├─ @includeWhen working memory  →  suppressed when DemoMode::enabled()
  └─ member-thought-row
        ProjectMemberThoughtPresenter (ObfuscatesDemoText on title/excerpt)
```

### New: `ProjectShowPresenter`

- `fromProject(Project $project): self`
- `pageTitle(): string` — obfuscates non-empty title; empty title unchanged
- `descriptionMarkdown(): ?string` — obfuscates non-empty description markdown; null/empty unchanged
- Uses `ObfuscatesDemoText` trait; delegates to `DemoObfuscator` with contexts `project_title` and `project_description`

### New: `ProjectContextThoughtPresenter`

- `fromThought(Thought $thought): self`
- `markdown(): string` — obfuscated content for `<x-safe-markdown>` when not microsite layout
- `displayLabel(): string` — obfuscated microsite page label when microsite layout
- Uses `thought_content` context (consistent with other thought body obfuscation)

### Extended: `ProjectMemberThoughtPresenter`

- Add `ObfuscatesDemoText` trait
- Apply obfuscation **after** existing title/excerpt derivation logic
- In demo mode, obfuscated strings replace derived plain text; derivation rules unchanged in normal mode
- `typeLabel()`, `url()`, `updatedAtHuman()` unchanged

### View changes

**`projects/show.blade.php`:**

- `@section('title', …)` and H1 use `ProjectShowPresenter::pageTitle()`
- Description uses presenter `descriptionMarkdown()` with `<x-safe-markdown>`
- Pass `editable => ! DemoMode::enabled()` to context partial
- Wrap working memory include: `@includeWhen(config('features.working_memory_ui') && ! demoMode, …)`
- Wrap sidebar "Add thought" and import sections with demo mode guard

**`projects/partials/context-thought.blade.php`:**

- Use `ProjectContextThoughtPresenter` for displayed text instead of raw `$contextThought->content` / `MicrositePageLabel`

**`projects/partials/member-thought-row.blade.php`:**

- Hide pin/remove action forms when `DemoMode::enabled()`
- Title/excerpt already via presenter (obfuscation internal to presenter)

No controller changes required beyond optional readability; presenters may be instantiated in Blade following existing patterns (e.g. `member-thought-row`).

## Obfuscation Behavior

Reuse existing `DemoObfuscator` contract:

- Session-scoped deterministic replacements
- Field-context namespacing (`project_title`, `project_description`, `thought_content`)
- Fail closed to `Demo content hidden` on error
- No persistence of obfuscated values

Empty/null project title or description remain empty/null.

## Banner And UX

Existing layout demo banner (`Demo mode enabled. Sensitive text is obfuscated.`) applies automatically via `layouts.idea` when session demo mode is on. No new banner copy required.

Presenter sees obfuscated project title, description, context, and member previews while counts, dates, and navigation remain realistic.

## Failure Handling

Same fail-closed rules as existing presenters: never fall back to raw classified strings in demo mode. Log warnings on obfuscation failure where other presenters do.

## Testing

### Unit

- `ProjectShowPresenterTest` — normal mode returns real title/description; demo mode returns obfuscated values; empty fields unchanged
- `ProjectContextThoughtPresenterTest` — markdown and microsite label obfuscated in demo mode
- Extend `ProjectMemberThoughtPresenterTest` — demo mode obfuscates derived title and excerpt; normal mode unchanged

### Feature (`ProjectShowDemoModeTest` or extend `ProjectCrudTest`)

With `config('services.demo_mode.enabled')` and session keys set:

1. Banner visible on `projects.show`
2. Raw project title, description, context content, and member thought secrets **not** in response HTML
3. Member count, dates, type labels, and thought links still present
4. Working memory block not rendered when feature flag on
5. Add thought form, import section, pin/remove controls not rendered
6. Toggling demo off restores raw text; DB records unchanged

Follow patterns in `tests/Feature/ThoughtShowPageTest.php`.

## Rollout

Single incremental slice after approval:

1. Add presenters
2. Extend `ProjectMemberThoughtPresenter`
3. Update three Blade files (show, context-thought, member-thought-row)
4. Add tests
5. Update `dev/demo-mode-obfuscation-v1-boundary.md` — add project show to covered list; note WM hidden (not obfuscated)

## Risks

| Risk | Mitigation |
|------|------------|
| Navigating to Graph/Index/Edit from show exposes real titles | Document as follow-up slices; acceptable for scope A |
| Stale session demo flag on non-covered routes | Existing env gate + banner; boundary doc lists gaps |
| Presenter misses a raw read in partial | Feature test asserts raw secrets absent from HTML |

## Follow-Up Slices (out of scope)

- `projects.index` card titles/descriptions
- `projects.graph` node labels (JSON endpoint)
- Working memory obfuscation if block should remain visible during demos
- API `/api/projects` field obfuscation

## Recommendation Summary

Extend demo mode to **project show only** using presenters for project title/description, pinned context, and member row title/excerpt. Hide working memory, sidebar add/import forms, and pin/remove controls in demo mode. No model or response-middleware changes. Reuse `DemoMode`, `DemoObfuscator`, and `ObfuscatesDemoText` from the March 2026 demo mode design.

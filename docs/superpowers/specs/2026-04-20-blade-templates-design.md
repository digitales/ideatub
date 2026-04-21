# IdeaTub — Clean Blade templates (no logic in views)

**Date:** 2026-04-20  
**Status:** Implemented (see [`docs/superpowers/plans/2026-04-20-blade-templates-cleanup.md`](../plans/2026-04-20-blade-templates-cleanup.md))  
**Scope:** Systematic refactor of idea, research, and shared-research Blade views so templates avoid PHP variable assignment, default merging, and presenter calls inside loops. Aligns with existing `App\View\Presenters` usage.

## Overview

Blade should focus on structure and output. **Defaults**, **derived booleans**, and **per-row presenter output** move to PHP classes (presenters, small DTOs, or class-based components) that are unit-tested. This spec defines conventions, a recommended architecture, migration order, and acceptance criteria for the focused pass (option **B** from brainstorming).

## Goals

- Remove `@php` blocks that **assign** variables used for business or presentation rules (defaults, `isset` chains, per-iteration presenter calls).
- Single **consistent** pattern across `idea/`, shared research, and related partials.
- **Testable** resolution of comment defaults and section thread props without rendering Blade.

## Non-goals

- Rewriting the entire `resources/views` tree in one go beyond the listed areas.
- Changing product behavior (guest vs owner, routes, authorization) unless a bug is discovered during refactor.
- Mandating Blade Components for every partial; they are **optional** where encapsulation helps.

## Conventions

### Allowed in Blade

- Control flow: `@if`, `@foreach`, `@isset` / `@empty` when driven by **already-resolved** view data.
- Echoing: `{{ }}`, `{!! !!}` where already sanctioned.
- `@include` / `@stack` / `@push` as today.
- Trivial display-only null coalescing is discouraged; prefer resolved values from PHP. If a scalar is optional for display, the view data contract should document it.

### Not allowed (in scope of this refactor)

- `@php … @endphp` blocks that **assign** `$variables` for defaults or loop-derived data.
- Calling presenter methods **inside** `@foreach` to build data for child includes (e.g. `sectionRowsFor` per iteration in the template).
- Duplicating default logic (`$commentsMode ?? 'owner'`, `route('comments.store')`, etc.) in multiple Blade files; centralize in one builder or presenter API.

## Architecture

### Primary pattern: presenter-centered resolved props

- Extend existing **`ResearchCommentsPresenter`** (or add a thin companion type) so that:
  - **Top-level defaults** are resolved in PHP: whether comments are active (`hasComments`), `commentsMode`, `commentsFormAction`, `commentsShowControls`.
  - **Per-section thread payload** for `comments._thread` is produced by explicit methods (e.g. `threadPropsForSection(Thought $section): array`) or small readonly **props/DTO** objects returned from those methods.

Call sites (controllers or shared view composers **only where already appropriate**) pass **either**:

- The presenter plus **pre-mapped** collections (e.g. keyed by section id), **or**
- A single **view data object** that exposes iterators of ready-made thread props.

The Blade partial iterates and passes arrays into `@include('comments._thread', …)` without computing rows or labels in the template.

### Optional pattern: class-based Blade components

For the largest partials (e.g. research content layout with sidebar), a **class-based** `<x-…>` component may wrap the same presenter/DTO logic so the template file contains almost no PHP. Use when it reduces duplication or clarifies boundaries; not mandatory for every file.

### What we avoid

- **View composers** for highly contextual partials (demo vs live, shared vs owner) unless the dependency graph stays obvious and tests cover them.
- **Splitting logic** between Blade and controller without a clear owner: the **builder** for a given screen owns the full shape passed to the view.

## Data flow

1. Entry point (controller or existing action) determines context (authenticated idea, shared research, demo).
2. It builds **`ResearchCommentsPresenter`** (or null) and resolves **defaults** in PHP.
3. It either:
   - Maps `$sections` to **thread props** in PHP (e.g. `collect($sections)->map(fn ($s) => …)` using presenter methods), or
   - Passes a **view data object** that encapsulates that map.
4. The Blade file receives **only** data ready for iteration and inclusion.

## Error handling and edge cases

- **No presenter:** Pass `hasComments === false` (or equivalent) from PHP; do not rely on `isset($commentsPresenter)` in Blade for core branching.
- **Section without `thought`:** Filter or represent as “no thread” in the mapped data so the template does not combine `isset` with business rules in the loop.
- **Disabled comments:** `disabledMessage` and flags are set in the props array/DTO in PHP, matching current behavior.

## Testing

- **Unit tests** for presenter methods or view-data builders: default `commentsMode`, `commentsFormAction` when presenter present vs absent, guest vs owner, per-section allowed/disallowed.
- **Feature or regression tests** where useful for critical paths after migration (optional but recommended for shared research and idea show).

## Migration scope and order

1. **Inventory:** Find `@php` and in-loop presenter usage under `resources/views/idea/`, `resources/views/shared_research/`, and includes they pull in.
2. **Implement** presenter/API changes and migrate **`idea/partials/research_content.blade.php`** and its callers first.
3. **Migrate** `shared_research/*` and remaining idea detail/partials that violate conventions.
4. **Verify** no new assign-heavy `@php` in migrated paths; document the convention for future PRs.

## Done criteria

- Migrated views in scope contain **no** `@php` assign blocks for defaults or per-section comment data.
- Defaults for comments (mode, form action, controls) exist in **one** PHP layer per flow, not duplicated in Blade.
- Presenter or builder output for `comments._thread` is **stable** and covered by unit tests.
- Call sites pass a clear, documented data shape (docblock on partial or typehinted view data).

## Open follow-up (post-implementation)

- Invoke **writing-plans** to produce the implementation plan and tasks from this spec.
- Optional lint: CI or custom script to flag `@php` in `resources/views/idea` and `shared_research` (can be a later increment).

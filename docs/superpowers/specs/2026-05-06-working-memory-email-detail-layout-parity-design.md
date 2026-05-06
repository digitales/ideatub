# Working Memory Email-Detail Layout Parity Design

## Objective

Align the Working Memory page layout with the existing email detail page structure so both global and project working memory use the same card-and-column experience users already recognize.

This design is intentionally scoped to UI composition and Blade structure. It does not change working memory data contracts or backend synthesis behavior.

## Scope

### In scope

- `resources/views/memory/show.blade.php` layout refactor.
- New shared detail layout shell partial for two-column detail pages.
- Working memory sidebar cards:
  - `Details` (first)
  - `Recent updates` (second, only when deltas exist)
- Mobile behavior changed from drawer to normal card stacking.
- Feature test updates for working memory web rendering.

### Out of scope

- `resources/views/memory/insights.blade.php` visual redesign.
- API/assembler payload changes.
- Working memory scoring/freshness logic changes.
- Broad style token refresh or design system migration.

## Confirmed product decisions

- Apply to both global and project working memory surfaces (`memory.show` route output).
- Use a visible sidebar on desktop.
- Move details into its own right-sidebar card (not a collapsible section in main content).
- Sidebar card order: `Details` first, `Recent updates` second.
- Use shared detail layout primitives (structural parity), not only CSS look-alike updates.

## Architecture

### Shared layout shell

Introduce a shared Blade partial (working name: `resources/views/idea/partials/detail_layout_shell.blade.php`) that encapsulates:

- Max-width page container and spacing rhythm used by existing detail pages.
- Responsive two-column grid:
  - Main content column (primary narrative card)
  - Sidebar column (metadata/actions/support cards)
- Mobile fallback to single-column stacked flow.

The shell should accept explicit slots/sections (header, main, sidebar) to avoid hidden coupling to thought-specific variables.

### Page-specific content remains local

The shell is shared, but card content remains feature-owned:

- Thought/email detail keeps existing content partials.
- Working memory renders its own cards and markdown body within the shared shell.

This keeps boundaries clear and avoids introducing a monolithic "universal detail page" partial.

## UX and layout behavior

### Header

Retain existing working memory header semantics:

- Title and scope subtitle (`Global` or project name context).
- Freshness pill.
- Global-only `Insights` link when feature-flagged.

### Main column

- Render the working memory summary markdown in the main narrative card.
- Maintain existing markdown safety options and prose styles.

### Right sidebar cards

Card 1: `Details`

- Confidence
- Last refreshed
- Consolidation window (days)
- Input count
- Baseline build type
- Recent updates count
- Missing values render as `—`

Card 2: `Recent updates` (conditional)

- Render only when overlay deltas exist.
- Preserve current per-delta fields (`label`, optional `detail`, optional `since`).
- If there are no deltas, omit this card completely.

### Responsive behavior

- Desktop/tablet wide view: persistent right sidebar (no drawer trigger).
- Narrow view: stack as a single column in this order:
  1. Header
  2. Main summary card
  3. Details card
  4. Recent updates card (if present)

## Data and backend impact

No contract changes:

- `MemoryController` continues returning current payload keys.
- `WorkingMemoryAssembler` and related services remain unchanged.
- No route or feature flag changes required for this design.

## Failure and edge-case handling

- Freshness badge states remain unchanged (`fresh`, `degraded`, `stale` mapping).
- Empty or missing markdown should continue to render safe fallback copy/presentation.
- Sidebar fields gracefully handle missing values without throwing template errors.
- Overlay-less payloads should not render visual artifacts from removed drawer logic.

## Testing plan

Update/add focused view behavior checks in `tests/Feature/WorkingMemoryWebTest.php`:

- Asserts `Details` block is rendered in working memory page output.
- Asserts `Recent updates` block renders when overlay deltas exist.
- Asserts `Recent updates` block is absent when overlay deltas are empty.
- Asserts removed drawer affordance text is not present (regression guard for old mobile UI pattern).

No service/API unit test changes are required because this is a presentation-only refactor.

## Rollout and risk

### Rollout

- Ship in one PR scoped to layout and working memory web tests.
- Keep shared shell extraction incremental; avoid broad migrations of unrelated views in this change.

### Risks

- Shared shell introduction could create unintended coupling with thought detail layout.
- Visual regressions may appear at responsive breakpoints.

### Mitigations

- Use explicit section inputs to the shared shell.
- Keep first consumer set narrow (working memory + optionally one existing detail page if low-risk).
- Verify on small and large viewport snapshots manually during implementation.

## Success criteria

- Working memory page visually follows email-detail-style card and column structure.
- Desktop uses persistent sidebar cards.
- Mobile no longer uses a drawer for recent updates.
- Details content lives in right sidebar first, recent updates second.
- Existing working memory data semantics and backend behavior are unchanged.

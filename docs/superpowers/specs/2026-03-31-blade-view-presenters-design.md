# Blade View Presenters Design

**Date:** 2026-03-31
**Status:** Ready for review
**Scope:** Refactor bloated Blade views across the app to use read-only presenters for derived display data while keeping database access and eager loading outside the view layer.

## Overview

Recent email formatting work exposed a broader pattern in the Blade layer: several views and partials now contain non-trivial derivation, fallback handling, formatting, and in at least one case direct database access.

This works functionally, but it makes templates harder to read, harder to reuse, and easier to regress. It also increases the risk of hidden query costs on list pages where presentation code is executed repeatedly.

The app should introduce a presenter layer for complex Blade rendering. Controllers and existing services should remain responsible for fetching and preloading data. Presenters should accept preloaded models, collections, arrays, or lookup maps and turn them into Blade-friendly display data without issuing queries.

## Goals

- Reduce view bloat in complex Blade templates and partials
- Move derived display logic and formatting out of Blade into dedicated presenter classes
- Prevent render-time database access from views and presenters
- Preserve the ability to render index and stream pages from preloaded data without extra queries
- Establish one consistent pattern for future Blade cleanup
- Refactor the known bloated and query-prone views in one focused sweep

## Non-Goals

- Replacing controllers with a full front-end view model framework
- Moving business workflows or persistence logic into presenters
- Rewriting every Blade file in the app regardless of complexity
- Changing user-visible behavior beyond what is necessary to preserve current output
- Introducing a presenter pattern that implicitly lazy loads missing relations

## Current State

The current Blade layer contains a mix of harmless template setup and heavier presentation logic. Based on the view scan, the most relevant cases fall into two groups.

### Derived display logic inside Blade

These views and partials contain noticeable formatting, fallback handling, conditional branching, or repeated derivation that would be easier to understand and test outside the template:

- `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- `resources/views/idea/show.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/partials/ideas_list.blade.php`
- `resources/views/idea/partials/completed_ideas_list.blade.php`
- `resources/views/layouts/idea.blade.php`

Examples include:

- building labels from status codes
- formatting participant lists from raw email metadata
- converting fallback metadata into display strings
- computing per-card activity timestamps
- deriving reply links, editability flags, and research display state
- formatting logged/completed date labels in templates

### Query-prone or render-time data access in Blade

At least one view currently performs a direct Eloquent query during render:

- `resources/views/settings/email-accounts.blade.php` calls `$mailAccount->syncRuns()->latest()->first()`

That pattern is especially risky because it scales with the number of rendered rows and makes performance dependent on template execution.

There are also several views that call model methods from Blade. Some may be cheap and some may be safe, but the new pattern should make these dependencies more explicit so complex pages are not relying on hidden data access.

## Approach Options Considered

### 1. Presenter per partial

Create a small presenter for each bloated partial or component.

This is simple to introduce and easy to review, but it can create a large number of tiny classes and does not automatically give strong list-page query boundaries.

### 2. Page view models everywhere

Create page-level view models for every complex screen and route all rendering through them.

This gives strong consistency and clear page contracts, but it adds a lot of ceremony and would likely over-model simpler screens.

### 3. Hybrid sweep

Use page-level presenters only for genuinely complex or list-heavy pages, and use smaller reusable presenters for shared fragments and repeated display logic.

Controllers continue to own querying and eager loading. Presenters accept preloaded data and expose render-ready fields.

## Recommendation

Use the hybrid sweep.

This matches the current codebase best because some controller methods already prepare view-oriented arrays, while the Blade layer still contains repeated derivation and formatting logic. The hybrid approach preserves the existing controller/service responsibilities, adds a clear presenter boundary, and avoids over-engineering simple views.

It also directly addresses the main performance concern: presenters can be made explicitly query-free, which keeps index and stream pages safe when rendering large preloaded collections.

## Presenter Architecture

Create a presenter namespace under `app/View/Presenters`.

Recommended structure:

- page presenters for complex list/detail pages
- item presenters for repeated rows or cards
- small reusable presenters for shared fragment logic

Probable first-wave presenters:

- `IdeaIndexPresenter`
- `IdeaCardPresenter`
- `IdeaStreamPresenter`
- `IdeaStreamThoughtPresenter`
- `ThoughtDetailPresenter`
- `EmailMetadataPresenter`
- `NewsletterResearchStatusPresenter`
- `MailAccountsSettingsPresenter`
- `MailAccountCardPresenter`

The exact class names can change, but the architectural split should remain:

- page presenters coordinate data already assembled for a screen
- item presenters adapt one model or one row of preloaded data
- fragment presenters encapsulate repeated display mapping and formatting

## Presenter Rules

Presenters should be read-only and side-effect free.

Required rules:

1. presenters do not issue queries
2. presenters do not call relation builders like `comments()` or `syncRuns()`
3. presenters do not rely on lazy-loaded relations
4. presenters may accept:
   - a preloaded Eloquent model
   - a preloaded collection
   - a plain array payload
   - a lookup map keyed by model id
5. presenters must not call model methods, accessors, or computed attributes unless they are documented and tested as query-free
6. presenters expose Blade-friendly scalars, arrays, booleans, and nested presenter objects
7. presenters may normalize, label, sort, format, and compose display data
8. presenters do not contain write operations, dispatch jobs, or mutate models

In development and tests, presenters should fail fast when a required relation or lookup payload is missing. Silent fallback to lazy loading would undermine the main purpose of the refactor.

Recommended enforcement:

- presenter constructors or builder/factory methods validate required lookup keys and loaded relations up front
- when a presenter depends on relation data, it should explicitly check `relationLoaded()` before reading that relation-shaped data
- missing required preload data should raise one consistent application exception rather than silently recovering
- enable Laravel lazy-loading prevention in local development and presenter/query-sensitive tests so violations fail loudly

## Data Flow And Query Safety

The query boundary should remain outside the presenter layer.

Expected flow:

1. controller or query-oriented service fetches data
2. controller ensures required relations and lookup maps are loaded
3. controller passes preloaded data into a presenter or presenter factory
4. presenter computes display-ready values
5. Blade renders simple properties and loops

### Preloaded list-page patterns

For list pages such as index, stream, and settings lists:

- preload shared relation data once
- build lookup maps once
- inject those maps into page or item presenters
- avoid per-row data discovery during template execution

Examples:

- `newsletterStatusByThoughtId`
- `shareByThoughtId`
- `latestSyncRunByAccountId`
- preloaded comments/parent relations

This is preferable to presenter methods that reach back into a model and ask for the missing data at render time.

### Relation expectations

When an item presenter depends on relations such as `comments`, `parent`, or `mailAccount`, that dependency should be explicit. The presenter should assume the relation is already loaded rather than trying to recover it itself.

This is especially important for:

- `idea` list cards
- stream cards
- email account settings rows
- any page that renders many records at once

### Nested presenter inputs

Nested presenters should not reopen the query boundary.

Preferred rule:

- child presenters receive pre-resolved scalars, arrays, lookup slices, or prebuilt child DTO-like payloads
- avoid passing a parent Eloquent model down into nested presenters unless the full loaded-data contract is explicitly shared and validated

This keeps reusable fragment presenters from accidentally reintroducing lazy loading through a whole model object.

### Presenter factories

If the refactor introduces presenter factories, keep them in the application layer near controllers or query-oriented services.

Presenter factories may:

- accept already-fetched models, collections, arrays, and maps
- validate preload requirements
- instantiate page, item, and fragment presenters

Presenter factories may not:

- accept a `Request` as their primary dependency
- issue queries implicitly as part of presenter construction
- blur into a second query service layer without being named and treated as such

## Refactor Targets

The focused sweep should start with the views that either contain direct queries or have the highest display-logic density.

### Priority 1: query-in-view and strong candidates

- `resources/views/settings/email-accounts.blade.php`
- `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`

### Priority 2: complex page and card rendering

- `resources/views/idea/show.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/partials/ideas_list.blade.php`
- `resources/views/idea/partials/completed_ideas_list.blade.php`

### Priority 3: follow-up cleanup

- `resources/views/layouts/idea.blade.php`
- other views that still contain repeated formatting or non-trivial branching after the first sweep

## Page-Specific Guidance

### `settings/email-accounts`

This page should stop querying for the latest sync run inside Blade.

Preferred direction:

- preload the latest sync run per account in the controller
- hand the result to a settings presenter or card presenter
- expose fields such as:
  - latest sync status label
  - latest sync relative timestamp
  - whether a latest run exists

The Blade file should only render those values.

### `idea` email sidebar and newsletter research status

The email-related partials are good reusable presenter candidates because they already behave like display fragments and are reused across different contexts.

Preferred direction:

- move newsletter status labeling and visibility flags into a `NewsletterResearchStatusPresenter`
- move email metadata fallback logic and participant formatting into an `EmailMetadataPresenter`
- keep the existing controller-side lookup/query helpers or convert them into presenter factories, as long as querying stays outside the presenter

### `idea` index and stream cards

These pages should likely use page presenters plus item presenters because they render repeated cards from preloaded collections and already depend on side data such as shares, comments, parents, and newsletter status maps.

Preferred direction:

- keep controller responsibility for loading models and side maps
- build presenters from the paginator items or collection items that are already loaded, rather than repeatedly traversing the paginator wrapper in Blade
- build item presenters that expose per-card values:
  - editability
  - reply link
  - activity timestamp label
  - type badge inputs
  - newsletter status presenter
  - tag row inputs

Blade should render cards, not derive card state.

### Completed and ideas lists

These pages contain lighter but still noisy display logic around date labels and research state branching.

Preferred direction:

- move date formatting and state labels into item presenters
- keep research collections pre-grouped outside the template
- let Blade render the already-prepared state rather than branching on multiple domain conditions

## Implementation Plan

The focused sweep should happen in four passes.

### Pass 1: establish presenter conventions

- create the presenter namespace
- document the no-query rule
- define naming and construction patterns
- decide where page presenters, item presenters, and fragment presenters live

### Pass 2: fix query-in-view cases first

- refactor `settings/email-accounts`
- audit obvious render-time query patterns discovered during implementation
- establish the preload-and-inject pattern before touching the larger `idea` pages

### Pass 3: sweep the bloated `idea` views

- extract newsletter research status presentation
- extract email metadata presentation
- introduce card presenters for stream/index
- simplify the thought detail page
- clean up completed and ideas list rendering

### Pass 4: cleanup and guardrails

- standardize controller payload assembly
- remove leftover duplicated formatting logic from Blade
- document the presenter convention for future work

## Testing

Testing should focus on both behavior preservation and query safety.

### Presenter tests

Add focused tests for presenter logic that is currently embedded in Blade, such as:

- newsletter status label and visibility mapping
- email participant formatting
- date label formatting
- activity timestamp fallback selection
- card display state derivation

### Feature tests

Keep or extend feature tests for representative pages:

- `settings.email-accounts`
- `idea.index`
- `idea.stream`
- `thoughts.show` for email thoughts

These tests should verify that pages still render the expected content once presenter-backed payloads are introduced.

### Query-sensitive coverage

Add focused checks for known high-risk pages so the refactor does not accidentally increase query counts or reintroduce O(n) render behavior.

Priority targets:

1. email accounts index
2. idea home/index card rendering
3. stream card rendering
4. email thought detail rendering

Recommended pattern:

1. seed a fixed number of records for each representative page, such as 15 to 25 cards or rows
2. render the route under test
3. capture executed queries using the project’s normal Laravel testing approach
4. assert the total query count stays within a fixed budget for that route
5. run these tests with lazy-loading prevention enabled so missing preload data fails loudly

The exact assertions can use the project’s existing testing style, but the goal is explicit: presenter adoption must not reintroduce hidden render-time queries.

## Risks

- Presenter boundaries may become inconsistent if page-level and fragment-level classes are mixed without conventions.
- Some model methods currently called from Blade may conceal query behavior that is only discovered during the refactor.
- Overusing presenters on trivial templates could add indirection without much readability benefit.
- If controllers do not fully preload required relations, presenter code could regress into lazy loading unless guarded carefully.

## Mitigations

- Document a strict no-query presenter rule
- Start with query-prone pages and list surfaces
- Fail fast when required preloaded data is missing
- Use page presenters only for truly complex or repeated rendering contexts
- Keep simple templates simple and do not force presenter usage where it does not materially improve clarity
- Prefer passing arrays or lookup slices to nested presenters rather than whole models on list-heavy pages
- Audit shared view-layer mechanisms such as view composers or shared layout data if they participate in these screens

## First Implementation Slice

The recommended first implementation slice is:

1. create the presenter namespace and conventions
2. refactor `settings/email-accounts` to remove the view query
3. extract `NewsletterResearchStatusPresenter`
4. extract `EmailMetadataPresenter`
5. add item presenters for stream and index cards
6. finish with completed and ideas list cleanup

This sequence provides an early performance win, establishes reusable patterns, and reduces risk before touching the largest list surfaces.

This first slice is the tactical sequence inside the same four-pass rollout above, not a separate plan.

## Recommendation Summary

Adopt a hybrid presenter pattern for Blade rendering across the app.

Controllers and query-oriented services should continue to fetch and preload data. Presenters should be strictly read-only adapters that accept preloaded models, arrays, and lookup maps and expose simple display-ready values to Blade. The initial focused sweep should prioritize query-prone views first, then clean up the densest `idea` templates and partials using shared presenters and item/page presenters where appropriate.

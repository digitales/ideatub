# Focused Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify the authenticated top navigation, move inbox access into the account menu with an avatar badge, group type-based destinations under a `Types` menu, and make routable thought-type labels clickable.

**Architecture:** Keep the implementation inside the existing Laravel + Blade patterns. Use one shared type-mapping helper so the `Types` menu and thought-label links cannot drift, reuse `IdeaController` + `idea.stream` for type collection pages where possible, and add small Blade partials for repeated UI (`Ideas` secondary nav and thought-type badges) instead of duplicating markup.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, Alpine.js, existing `tests/Feature` / `tests/Unit` PHPUnit-style test classes

**Spec:** `docs/superpowers/specs/2026-03-21-focused-navigation-design.md`

---

## File structure

### Existing files to modify

- `routes/web.php`
  - Add stable routes for the new type pages.
- `app/Http/Controllers/IdeaController.php`
  - Reuse the existing stream-style rendering for `Emails`, `Research`, and `Plans`.
- `app/Models/Thought.php`
  - Add small query scopes/helpers for thought-type filtering if the controller logic starts repeating.
- `resources/views/layouts/idea.blade.php`
  - Restructure the authenticated top nav, add `Types`, move `Inbox` into the avatar menu, and move the actionable badge onto the avatar button.
- `resources/views/idea/ideas.blade.php`
  - Render the new `Ideas` secondary nav.
- `resources/views/idea/revisit.blade.php`
  - Render the same `Ideas` secondary nav and preserve the stable direct route.
- `resources/views/idea/stream.blade.php`
  - Generalize the page title / empty-state copy so the existing stream page can render `Jira`, `Emails`, `Research`, and `Plans`.
- `resources/views/idea/index_thought_cards.blade.php`
  - Replace the plain source label with the shared thought-type badge partial.
- `resources/views/idea/stream_thoughts.blade.php`
  - Replace the plain source label with the shared thought-type badge partial.
- `resources/views/idea/partials/thought_detail_header.blade.php`
  - Make the detail-page type label use the same shared partial.

### New files to create

- `app/Support/ThoughtTypeNavigation.php`
  - Single source of truth for canonical type keys, labels, route names, and availability rules.
- `resources/views/idea/partials/ideas_section_nav.blade.php`
  - Shared secondary nav for `Ideas` and `Ideas to revisit`.
- `resources/views/idea/partials/thought_type_badge.blade.php`
  - Shared render path for clickable/non-clickable thought type labels.
- `tests/Feature/ThoughtTypePagesTest.php`
  - Focused coverage for the new type collection routes and thought-label link behavior.
- `tests/Unit/Support/ThoughtTypeNavigationTest.php`
  - Fast coverage for canonical mapping, alias normalization, and availability rules.

### Existing tests to modify

- `tests/Feature/IdeaPageTest.php`
  - Nav assertions on the main authenticated home page.
- `tests/Feature/InboxPageTest.php`
  - Account-menu inbox presence and avatar badge assertions.
- `tests/Feature/IdeaIdeasTest.php`
  - `Ideas` secondary-nav assertions.
- `tests/Feature/IdeasToRevisitPageTest.php`
  - `Ideas to revisit` secondary-nav assertions and direct-route expectations.
- `tests/Feature/ThoughtShowPageTest.php`
  - Detail-page thought-type label rendering.
- `tests/Feature/StreamPageTest.php`
  - Existing Jira stream tests may move to or be supplemented by `ThoughtTypePagesTest.php`.

---

## Task 1: Add shared thought-type routing and collection pages

**Files:**
- Create: `app/Support/ThoughtTypeNavigation.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/Models/Thought.php`
- Modify: `resources/views/idea/stream.blade.php`
- Create: `tests/Feature/ThoughtTypePagesTest.php`
- Create: `tests/Unit/Support/ThoughtTypeNavigationTest.php`

### Background

The codebase already has one dedicated type-like route, `route('idea.stream.jira')`, plus a generic tag-filtered stream. There are no first-class routes yet for `Emails`, `Research`, or `Plans`, and the spec requires one canonical mapping shared by both the `Types` menu and clickable thought labels.

Keep this centralized. Do not scatter route-name strings and `source` / `metadata.type` conditionals across multiple views.

---

- [ ] **Step 1: Create failing unit tests for the shared type-mapping helper**

Create `tests/Unit/Support/ThoughtTypeNavigationTest.php` with fast assertions for:

- ordered nav types are `jira`, `email`, `research`, `plan`
- labels map to `Jira`, `Emails`, `Research`, `Plans`
- aliases normalize correctly (`emails` -> `email`, `plans` -> `plan`)
- availability honors `config('services.jira.enabled', true|false)`
- a `Thought`-like fixture resolves correctly from `source` / `metadata.type`

- [ ] **Step 2: Run the new unit tests to verify they fail**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/Support/ThoughtTypeNavigationTest.php --stop-on-failure
```

Expected: FAIL because the helper does not exist yet.

- [ ] **Step 3: Create the shared type-mapping helper**

Create `app/Support/ThoughtTypeNavigation.php` with one public API for:

- returning the ordered list of nav types (`jira`, `email`, `research`, `plan`)
- returning the collection/menu label (`Jira`, `Emails`, `Research`, `Plans`)
- returning the per-thought display label (`Jira`, `Email`, `Research`, `Plan`) so card badges can stay singular where the spec expects that
- returning the route name for each type
- deciding whether a type is available in the current app config (for example, `jira` should honor `config('services.jira.enabled', true)`)
- resolving a `Thought` into a routable type key using:
  - `source = jira` -> `jira`
  - `source = email` -> `email`
  - `metadata.type = research` -> `research`
  - `metadata.type = plan` -> `plan`
- normalizing simple stored aliases to the same canonical key where needed (for example `emails` -> `email`, `plans` -> `plan`)

Keep this file dumb and deterministic. No DB work here.

- [ ] **Step 4: Run the helper unit tests again**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/Support/ThoughtTypeNavigationTest.php
```

Expected: PASS.

- [ ] **Step 5: Create failing tests for the four type collection pages**

Create `tests/Feature/ThoughtTypePagesTest.php` with focused route tests for:

```php
public function test_jira_type_page_shows_only_jira_thoughts(): void
public function test_emails_type_page_shows_only_email_thoughts(): void
public function test_research_type_page_shows_only_research_thoughts(): void
public function test_plans_type_page_shows_only_plan_thoughts(): void
public function test_type_page_shows_empty_state_when_no_matching_thoughts_exist(): void
public function test_disabled_jira_type_is_not_available_in_navigation_mapping(): void
```

Assert each route only shows its matching records and exposes the correct page title text. For the disabled Jira case, set `config(['services.jira.enabled' => false])` and assert the shared helper reports Jira unavailable so later Blade tests can enforce parity on both surfaces.

- [ ] **Step 6: Add small filtering helpers to `Thought` if the controller needs them**

If `IdeaController` starts duplicating `source` / `metadata.type` filters, add focused scopes such as:

```php
public function scopeOfSource(Builder $query, string $source): Builder
public function scopeOfMetadataType(Builder $query, string $type): Builder
```

Do not add an over-general abstraction if two small scopes are enough.

- [ ] **Step 7: Add stable routes for `Emails`, `Research`, and `Plans`**

In `routes/web.php`, add named authenticated GET routes alongside the existing stream routes:

```php
Route::get('/stream/emails', [IdeaController::class, 'streamEmails'])->name('idea.stream.emails');
Route::get('/stream/research', [IdeaController::class, 'streamResearch'])->name('idea.stream.research');
Route::get('/stream/plans', [IdeaController::class, 'streamPlans'])->name('idea.stream.plans');
```

Keep `route('idea.stream.jira')` as-is so existing Jira behavior does not break.

- [ ] **Step 8: Implement the three controller actions by reusing the existing stream view**

In `app/Http/Controllers/IdeaController.php`, add:

```php
public function streamEmails(Request $request): View|JsonResponse
public function streamResearch(Request $request): View|JsonResponse
public function streamPlans(Request $request): View|JsonResponse
```

Implementation rules:

- paginate like the existing stream pages
- filter by:
  - `source = email`
  - `metadata.type = research`
  - `metadata.type = plan`
- load comments like the existing stream methods
- reuse `idea.stream`
- pass enough page-state data for:
  - title (`Emails`, `Research`, `Plans`)
  - empty-state copy
  - "All thoughts" back link

If the duplication with `streamJira()` becomes too large, extract one private helper that accepts query + title metadata. Do not rewrite the entire stream system.

- [ ] **Step 9: Generalize `resources/views/idea/stream.blade.php` just enough for typed collection pages**

Update the view so it can render:

- default stream
- tag stream
- Jira stream
- Emails stream
- Research stream
- Plans stream

Keep the existing tag behavior intact. The simplest shape is a small `streamMode` / `streamTitle` / `emptyCopy` variable set from the controller.

- [ ] **Step 10: Run the route tests again**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/ThoughtTypePagesTest.php
```

Expected: PASS.

- [ ] **Step 11: Run the existing stream regressions**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/StreamPageTest.php
```

Expected: PASS, including the existing Jira stream behavior.

- [ ] **Step 12: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add app/Support/ThoughtTypeNavigation.php app/Http/Controllers/IdeaController.php resources/views/idea/stream.blade.php routes/web.php tests/Feature/ThoughtTypePagesTest.php tests/Unit/Support/ThoughtTypeNavigationTest.php
git commit -m "feat: add typed thought collection pages"
```

---

## Task 2: Restructure the top nav and move inbox into the account menu

**Files:**
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `tests/Feature/IdeaPageTest.php`
- Modify: `tests/Feature/InboxPageTest.php`

### Background

`resources/views/layouts/idea.blade.php` currently renders `Ideas`, `Inbox`, `Ideas to revisit`, `Stream`, `Jira`, `Help`, and `Keyboard shortcuts` as flat top-level items. The inbox badge already exists and is driven by the shared `inboxActionableCount` composer in `AppServiceProvider`.

Do not create a second inbox count mechanism. Move the existing signal to the avatar button by reusing the already-shared `inboxActionableCount` value from the existing view composer.

---

- [ ] **Step 1: Add minimal stable test hooks to the current layout before changing nav structure**

In `resources/views/layouts/idea.blade.php`, add small `data-testid` hooks to the existing nav so the first red/green cycle can assert structure cleanly:

- `data-testid="primary-nav"`
- `data-testid="types-menu-trigger"` (placeholder is fine until `Types` exists)
- `data-testid="types-menu-list"` (placeholder is fine until `Types` exists)
- `data-testid="mobile-nav-trigger"`
- `data-testid="mobile-nav-panel"`
- `data-testid="avatar-inbox-badge"`
- `data-testid="account-menu-inbox-link"`

- [ ] **Step 2: Write failing nav tests on the authenticated layout**

Add focused assertions in `tests/Feature/IdeaPageTest.php` and `tests/Feature/InboxPageTest.php` for:

- primary nav includes `Ideas`, `Stream`, `Types`, `Help`, `Keyboard shortcuts`
- primary nav no longer includes top-level `Inbox`
- primary nav no longer includes top-level `Ideas to revisit`
- primary nav no longer includes top-level `Jira`
- account menu markup includes `Inbox`
- actionable inbox count renders on the avatar button, not on a standalone inbox nav pill
- zero actionable items render no avatar badge
- counts above 99 render `99+`
- the `Types` menu renders `Jira`, `Emails`, `Research`, `Plans` in that exact order
- when `config('services.jira.enabled', false)`, Jira is absent from the `Types` menu

Also add a layout-level feature test that simulates the narrow-screen fallback by asserting a dedicated mobile/overflow trigger or secondary menu container exists once the responsive nav is introduced. Do not rely on CSS-only wrapping as the mobile solution.

Prefer raw HTML assertions with stable `data-testid` markers rather than brittle substring counting.

- [ ] **Step 3: Run the updated nav tests to verify they fail**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php tests/Feature/InboxPageTest.php --stop-on-failure
```

Expected: FAIL because the old nav still renders the flat link list.

- [ ] **Step 4: Replace the flat top nav with the focused structure**

Update `resources/views/layouts/idea.blade.php` so the visible labeled nav cluster is:

- `Ideas`
- `Stream`
- `Types`
- `Help`
- `Keyboard shortcuts`

Implementation notes:

- `Types` can be a lightweight Alpine dropdown in the existing nav bar
- keep the app wordmark, search trigger, and avatar where they already fit
- reuse the existing avatar dropdown for account-menu items
- move the `Inbox` link into that avatar dropdown
- keep the existing `Jira` settings link inside the account menu if it still belongs there as a settings page
- for smaller viewports, add an explicit compact/overflow nav pattern (for example a menu button that reveals the focused nav items) instead of letting the desktop nav wrap into two rows

- [ ] **Step 5: Move the inbox badge to the avatar button**

Use the existing `inboxActionableCount` shared from `AppServiceProvider`.

Rules to implement:

- render nothing when count is `0`
- render the count when `1..99`
- render `99+` when count is greater than `99`
- include screen-reader text such as `Inbox has 3 actionable items`

This may only require markup changes in the layout; `AppServiceProvider` should stay unchanged unless you need to rename the shared variable or add a view helper comment.

- [ ] **Step 6: Add the `Types` dropdown contents in the approved order**

Inside `resources/views/layouts/idea.blade.php`, render:

- `Jira`
- `Emails`
- `Research`
- `Plans`

Use `ThoughtTypeNavigation` for labels, route names, ordering, and availability checks so the menu stays aligned with the later thought-label links.

- [ ] **Step 7: Add responsive-nav assertions before considering the task done**

Verify in markup that the small-screen fallback contains reachable entries for:

- `Ideas`
- `Stream`
- `Types`
- `Help`
- `Keyboard shortcuts`

The implementation does not need browser automation here, but it must ship identifiable overflow/mobile-nav markup rather than relying on CSS wrap.

- [ ] **Step 8: Run the nav tests again**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php tests/Feature/InboxPageTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add resources/views/layouts/idea.blade.php tests/Feature/IdeaPageTest.php tests/Feature/InboxPageTest.php
git commit -m "feat: simplify top navigation and move inbox into account menu"
```

---

## Task 3: Add `Ideas` secondary navigation for `Ideas to revisit`

**Files:**
- Create: `resources/views/idea/partials/ideas_section_nav.blade.php`
- Modify: `resources/views/idea/ideas.blade.php`
- Modify: `resources/views/idea/revisit.blade.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`
- Modify: `tests/Feature/IdeasToRevisitPageTest.php`

### Background

`route('idea.revisit')` already exists and must remain stable/bookmarkable. The change here is not routing; it is information architecture and local navigation inside the `Ideas` area.

Reuse one partial so `Ideas` and `Ideas to revisit` cannot drift visually.

---

- [ ] **Step 1: Write failing tests for the `Ideas` secondary nav**

Add assertions that:

- `route('idea.ideas')` shows links to both `Ideas` and `Ideas to revisit`
- `route('idea.revisit')` shows the same secondary nav
- the active item is visually marked (assert a stable `aria-current="page"` or `data-active="true"` hook)
- `route('idea.revisit')` still responds directly

- [ ] **Step 2: Run the two idea-page test files to verify they fail**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php --stop-on-failure
```

Expected: FAIL because there is no shared secondary-nav markup yet.

- [ ] **Step 3: Create the shared partial**

Create `resources/views/idea/partials/ideas_section_nav.blade.php` with two links:

- `route('idea.ideas')`
- `route('idea.revisit')`

Use explicit active-state input, for example:

```blade
@include('idea.partials.ideas_section_nav', ['active' => 'ideas'])
@include('idea.partials.ideas_section_nav', ['active' => 'revisit'])
```

Keep the markup simple and reusable.

- [ ] **Step 4: Render the partial in both page templates**

Insert the partial:

- below the `<h1>` in `resources/views/idea/ideas.blade.php`
- below the `<h1>` / intro block in `resources/views/idea/revisit.blade.php`

Do not remove the existing direct route or change the page titles.

- [ ] **Step 5: Run the idea-page tests again**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add resources/views/idea/partials/ideas_section_nav.blade.php resources/views/idea/ideas.blade.php resources/views/idea/revisit.blade.php tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php
git commit -m "feat: add ideas secondary navigation"
```

---

## Task 4: Make thought-type labels clickable from thought surfaces

**Files:**
- Create: `resources/views/idea/partials/thought_type_badge.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Modify: `tests/Feature/ThoughtTypePagesTest.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`

### Background

Several thought surfaces currently render a plain source label like:

```blade
<span class="text-[10.5px] text-slate-brand/40">{{ ucfirst(strtolower($thought->source)) }}</span>
```

This is the right insertion point for clickable type labels. Keep the badge lightweight and metadata-like; do not turn it into a heavy CTA.

---

- [ ] **Step 1: Add failing tests for clickable thought-type labels**

Extend `tests/Feature/ThoughtTypePagesTest.php` and `tests/Feature/ThoughtShowPageTest.php` with coverage for:

- an email-sourced thought renders a link to `route('idea.stream.emails')`
- a Jira-sourced thought renders a link to `route('idea.stream.jira')`
- a research thought renders a link to `route('idea.stream.research')`
- a plan thought renders a link to `route('idea.stream.plans')`
- a non-routable type does not render a fake link (`href="#"`, empty href, etc.)
- missing or malformed type metadata does not throw and does not render a broken link
- when `config('services.jira.enabled', false)`, Jira thoughts do not render clickable Jira type links
- each routable mapping renders the canonical expected `href` for that thought type
- for at least one clickable example per route family, the linked destination returns `200` for the thought owner

Use raw HTML assertions so you can distinguish `<a>` from `<span>`.

- [ ] **Step 2: Run the focused type-label tests to verify they fail**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/ThoughtTypePagesTest.php tests/Feature/ThoughtShowPageTest.php --stop-on-failure
```

Expected: FAIL because the current UI still renders plain text source labels.

- [ ] **Step 3: Create the shared badge partial**

Create `resources/views/idea/partials/thought_type_badge.blade.php`.

Inputs should include at least:

- `thought`
- optional `class` override for minor size/context adjustments

Inside the partial:

- call `ThoughtTypeNavigation` to resolve the thought into a type key
- if the type is available and routable, render an `<a>`
- if the thought has a recognizable but unavailable / non-routable type, render a non-link `<span>`
- if the thought has a recognizable but malformed type value, render a neutral non-link label only when there is still a meaningful human-readable label to show; otherwise render nothing
- if the thought has no relevant type/source at all, render nothing

For clickable labels, include:

- visible hover styling
- visible keyboard focus styling
- semantic `<a>` markup with a real route href

Do not duplicate routing logic in the calling views.

- [ ] **Step 4: Replace the plain labels in the shared thought surfaces**

Update:

- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- `resources/views/idea/partials/thought_detail_header.blade.php`

Place the new partial where the old source label lived so the layout remains familiar.

Leave `thought_tag_row` alone; this task is about type labels, not tags.

- [ ] **Step 5: Run the type-label tests again**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/ThoughtTypePagesTest.php tests/Feature/ThoughtShowPageTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the broader page regressions**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/ThoughtShowPageTest.php
```

Expected: PASS.

- [ ] **Step 7: Add one parity regression that checks unavailable destinations stay consistent across both surfaces**

Use a single config-driven scenario on `route('idea.index')` (it renders both the authenticated layout and top-level thought cards) where:

- the layout is rendered
- a Jira thought is present on the page
- the `Types` menu omits Jira
- the thought-type badge renders as non-clickable

Place this in whichever feature test keeps the fixture smallest, but make sure one test exercises both the top nav and the thought-card label together.

- [ ] **Step 8: Commit**

```bash
cd /Users/rosstweedie/Sites/ideatub && git add resources/views/idea/partials/thought_type_badge.blade.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php resources/views/idea/partials/thought_detail_header.blade.php tests/Feature/ThoughtTypePagesTest.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: make thought type labels navigable"
```

---

## Task 5: Final regression pass

**Files:**
- No planned source edits

---

- [ ] **Step 1: Run the full focused-nav regression set**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test \
  tests/Feature/IdeaPageTest.php \
  tests/Feature/InboxPageTest.php \
  tests/Feature/IdeaIdeasTest.php \
  tests/Feature/IdeasToRevisitPageTest.php \
  tests/Feature/ThoughtTypePagesTest.php \
  tests/Feature/ThoughtShowPageTest.php \
  tests/Feature/StreamPageTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the full test suite**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test
```

Expected: PASS.

- [ ] **Step 3: Final commit (only if Task 1-4 were not already committed separately)**

```bash
cd /Users/rosstweedie/Sites/ideatub && git status
```

Expected: clean working tree.

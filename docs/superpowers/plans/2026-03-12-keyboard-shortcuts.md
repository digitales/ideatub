# Keyboard Shortcuts (IdeaTub) Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add keyboard shortcuts to the IdeaTub thinking space (focus capture, search, thought list navigation, shortcut palette, Escape behaviour) with inline hints and a Help page, using Alpine.js and a single document-level key handler.

**Architecture:** One `@keydown.window` handler in the idea layout runs for all shortcuts; context guard skips most shortcuts when focus is in input/textarea (Escape always runs). Layout dispatches custom events for “focus capture” and “thought nav” so the idea index view can handle them without the layout needing refs into yielded content. Shortcut palette and Help page surface the same binding table.

**Tech Stack:** Laravel Blade, Alpine.js (already in use), Tailwind CSS. No new JS bundle.

**Spec:** `docs/superpowers/specs/2026-03-12-keyboard-shortcuts-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `resources/views/layouts/idea.blade.php` | Add wrapper `x-data` with `searching`, `query`, `shortcutsOpen`; global `@keydown.window`; Escape / ⌘K / ⌘/ / ? handling; shortcut palette markup; “Keyboard shortcuts” nav link; optional search hint. |
| `resources/views/idea/index.blade.php` | Add `@focus-capture.window` on capture box; thought-list wrapper with `x-data` for `selectedThoughtIndex`, `@thought-nav` / `@thought-reply` listeners, data attributes for reply URLs; inline hints (capture, list); `x-ref` on textarea for focus. |
| `routes/web.php` | Add GET `/help` route (auth middleware). |
| `app/Http/Controllers/HelpController.php` | Create controller with `index()` returning help view. |
| `resources/views/help.blade.php` | Create view extending idea layout (or app layout) with shortcut table and optional copy. |

---

## Chunk 1: Layout — global key handler and Escape / ⌘K / ⌘/ / ?

### Task 1.1: Wrapper and global key handler in idea layout

**Files:** Modify `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Wrap body content in a single Alpine component and add key handler**

  - Wrap the existing `<nav>` and `<main>` in a single `<div>` with `x-data` that holds: `searching`, `query`, `shortcutsOpen` (all in one scope so the nav and key handler share state).
  - Move the nav’s current `x-data="{ searching: false, query: '...' }"` into this wrapper so it becomes e.g. `x-data="{ searching: false, query: '{{ old('q', $query ?? '') }}', shortcutsOpen: false }"`.
  - Add `@keydown.window.prevent.self="handleKey($event)"` on this wrapper (we’ll add `handleKey` in the next step). Use `.prevent.self` only where needed so we don’t break default behaviour everywhere; for keys we handle, call `$event.preventDefault()` inside the handler where appropriate.

  Implementation detail: Use a single wrapper div around the whole app (nav + main). Use inline `x-data` so the initial `query` can come from Blade, e.g. `x-data="{ searching: false, query: '{{ old('q', $query ?? '') }}', shortcutsOpen: false, handleKey(e) { ... } }"`. Escape the query for JS if it contains quotes (or use `@json()` for safety). Attach `@keydown.window="handleKey($event)"` on this wrapper. For now implement `handleKey` as a stub that only checks context: if `document.activeElement` is input/textarea/select and `e.key !== 'Escape'`, return. Then implement Escape: if `this.shortcutsOpen` then `this.shortcutsOpen = false`; else if `this.searching` then `this.searching = false`; else do nothing. Call `e.preventDefault()` when we handle the key.

  Note: Nav currently has `x-data="{ searching: false, query: '...' }"`. We need to lift that state to the wrapper and remove it from the nav so the wrapper’s `handleKey` can set `searching = true` for ⌘K. So the wrapper will have `searching`, `query`, `shortcutsOpen`, and the nav will no longer have its own `x-data` (it will use the wrapper’s state). That means the nav’s `x-show="searching"`, `x-model="query"`, `@click="searching = true"` etc. must work from the parent. So the wrapper wraps both nav and main; the nav’s bindings reference the wrapper’s state. Do the same for the search form’s `x-show="searching"` and the search input’s `x-model="query"` and `x-ref="searchInput"`. So the search input’s `x-ref` will be on the wrapper’s scope. After this step, Escape and context guard work; ⌘K and ⌘/ and ? will be wired in the next tasks.

- [ ] **Step 2: Implement ⌘K and ⌘/ in handleKey**

  In `handleKey`: if not in input/textarea (and not already handled), if `(e.metaKey || e.ctrlKey) && e.key === 'k'`: set `this.searching = true`, `$nextTick(() => this.$refs.searchInput?.focus())`, `e.preventDefault()`. If `(e.metaKey || e.ctrlKey) && e.key === '/'`: `$dispatch('focus-capture')`, `e.preventDefault()`.

- [ ] **Step 3: Implement ? to open shortcut palette**

  In `handleKey`: if not in input/textarea and `e.key === '?'` (Shift+/): set `this.shortcutsOpen = true`, `e.preventDefault()`. Ensure Escape already closes `shortcutsOpen` from Step 1.

- [ ] **Step 4: Add “Keyboard shortcuts” nav link**

  In the right nav (next to “Help”), change or add a link: either make “Help” point to `route('help')` when we add it, or add a button/link that does `@click="shortcutsOpen = true"` with label “Keyboard shortcuts”. Spec says both ? and a nav link open the palette; so add a nav item that sets `shortcutsOpen = true`.

- [ ] **Step 5: Manual test and commit**

  Test in browser: open idea index, focus outside inputs, press Escape (no crash), ⌘K (search opens, focus in search), ⌘/ (dispatch; we’ll verify focus in Chunk 2), ? (palette not yet rendered; we’ll add in Chunk 2). Commit: `feat(shortcuts): global key handler and Escape, ⌘K, ⌘/, ? in idea layout`

---

## Chunk 2: Shortcut palette and search hint

### Task 2.1: Shortcut palette markup in idea layout

**Files:** Modify `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Add palette overlay and table**

  Inside the wrapper div (after `<main>`), add a div that shows when `shortcutsOpen` is true: `x-show="shortcutsOpen"`, `x-transition`, `@keydown.escape.window="shortcutsOpen = false"`, `@click.away="shortcutsOpen = false"`. Content: heading “Keyboard shortcuts” and a table (or list) with rows: Focus capture — ⌘/ (Ctrl+/); Open search — ⌘K (Ctrl+K); Move down/up thought — j / k or ↓ / ↑; Open reply — Enter; Cancel reply / close search — Escape; Submit thought — ⌘+Enter; Show this list — ?. Style to match the idea layout (e.g. same card/overlay style as capture box).

- [ ] **Step 2: Optional search overlay hint**

  Inside the search form, add a small hint line: “Escape to close · ⌘K to focus search” (only when search is open). Optional: on the “Find a memory” pill add “⌘K” as a small hint.

- [ ] **Step 3: Manual test and commit**

  Open idea index, press ?, palette appears; Escape or click away closes it. Commit: `feat(shortcuts): shortcut palette and search hint in idea layout`

---

## Chunk 3: Focus capture and thought list navigation (idea index)

### Task 3.1: Focus capture listener and hint

**Files:** Modify `resources/views/idea/index.blade.php`

- [ ] **Step 1: Capture box listens for focus-capture and add ref**

  On the capture box div (the one with `x-data="{ content: '...' }"`), add `@focus-capture.window="$refs.captureTextarea?.focus()"`. On the textarea, add `x-ref="captureTextarea"`. Ensure the form still has `@keydown.meta.enter.prevent="$el.submit()"`.

- [ ] **Step 2: Inline hint under capture**

  In the hint line that currently says “⌘ + Enter to store”, add “ · ⌘/ to focus” (or “⌘/ to focus” next to it).

- [ ] **Step 3: Manual test**

  From idea index, press ⌘/ (or Ctrl+/) with focus outside inputs; capture textarea should receive focus. Commit: `feat(shortcuts): focus capture and inline hint`

### Task 3.2: Thought list selection and j/k/Enter

**Files:** Modify `resources/views/idea/index.blade.php`

- [ ] **Step 1: Wrap thought list in Alpine component with state**

  Wrap the thoughts list (the `@forelse` block and the “Thoughts list” header + empty state) in a div with `x-data` that holds: `selectedThoughtIndex: 0`, and a computed or derived value for the number of replyable thoughts. We need to pass reply URLs from Blade: add a hidden container or data on each thought card so the Alpine component can read them. Option: each thought card (the outer `<div>` of each thought) gets `data-index="{{ $loop->index }}"` and `data-reply-href="{{ !$thought->parent_id ? route('idea.index', ['parent_id' => $thought->id]) : '' }}"`. So only top-level thoughts get a reply href.

- [ ] **Step 2: Listen for thought-nav and thought-reply**

  On this wrapper, add `@thought-nav.window="handleThoughtNav($event.detail)"` and `@thought-reply.window="handleThoughtReply()"`. Implement: `handleThoughtNav(detail)` where `detail.direction` is `'next'` or `'prev'`: clamp `selectedThoughtIndex` and update; optionally scroll the selected card into view. `handleThoughtReply()`: get the card at `selectedThoughtIndex` (e.g. `this.$el.querySelectorAll('[data-reply-href]')[this.selectedThoughtIndex]`), read `data-reply-href`, and if present set `window.location.href = href`.

- [ ] **Step 3: Dispatch thought-nav and thought-reply from layout**

  In the layout’s `handleKey`, when not in input/textarea: if `e.key === 'j' || e.key === 'ArrowDown'`: `$dispatch('thought-nav', { direction: 'next' })`, `e.preventDefault()`. If `e.key === 'k' || e.key === 'ArrowUp'`: `$dispatch('thought-nav', { direction: 'prev' })`, `e.preventDefault()`. If `e.key === 'Enter'`: `$dispatch('thought-reply')`, `e.preventDefault()`. (The idea index handler will only navigate if the selected thought has a reply link.)

- [ ] **Step 4: Visual selection state and list hint**

  On each thought card, add a class or attribute when it’s selected, e.g. `:class="{ 'ring-2 ring-memory-violet': selectedThoughtIndex === {{ $loop->index }} }"` (or bind to a data-index and compare in Alpine). Add a one-line hint above or below the list: “↑↓ or j/k to move · Enter to reply” (show only when there is at least one thought).

- [ ] **Step 5: Manual test and commit**

  Test j/k and Enter on idea index with several thoughts; selection moves and Enter goes to reply. Commit: `feat(shortcuts): thought list navigation (j/k, Enter) and selection state`

---

## Chunk 4: Help page and nav link

### Task 4.1: Help route and controller

**Files:** Create `app/Http/Controllers/HelpController.php`; Modify `routes/web.php`

- [ ] **Step 1: Create HelpController**

  New controller with `index()` that returns `view('help')` (or `view('help.index')` if you prefer a subdir). No props required if the view only shows static content.

- [ ] **Step 2: Register route**

  In `routes/web.php`, inside the `auth` middleware group, add: `Route::get('/help', [HelpController::class, 'index'])->name('help');`

- [ ] **Step 3: Commit**

  Commit: `feat(help): add Help controller and route`

### Task 4.2: Help view and shortcut table

**Files:** Create `resources/views/help.blade.php`; Modify `resources/views/layouts/idea.blade.php` (nav link)

- [ ] **Step 1: Create help view**

  Create `resources/views/help.blade.php` that extends the idea layout (`layouts.idea`). Content: title “Help” or “Keyboard shortcuts”, then the same shortcut table as in the design (and as in the palette). Use the same table structure so it’s the single source of truth for “what’s documented”.

- [ ] **Step 2: Point Help nav link to help route**

  In `resources/views/layouts/idea.blade.php`, change the “Help” link from `href="#"` to `href="{{ route('help') }}"`.

- [ ] **Step 3: Manual test and commit**

  Visit `/help`; see shortcut table. Nav “Help” goes to `/help`. Commit: `feat(help): Help page with keyboard shortcuts table and nav link`

---

## Chunk 5: Polish and verification

### Task 5.1: Escape in capture and reply context

**Files:** Modify `resources/views/layouts/idea.blade.php` and/or `resources/views/idea/index.blade.php`

- [ ] **Step 1: Escape when in search**

  Already handled: layout sets `searching = false`. No change needed if already done.

- [ ] **Step 2: Escape when replying (cancel reply)**

  When the user is on the “replying to” screen (URL has `parent_id`), pressing Escape should go back to idea index (same as “Cancel”). Options: (A) In layout’s handleKey, when Escape and not in input/textarea, check if current URL has `parent_id` and then `window.location = route('idea.index')` (we’d need to pass the URL from Blade or read from DOM). (B) In idea index, add a small Alpine listener on the capture box: `@keydown.escape.window` that only runs when replying and focus is in capture, and redirect to `route('idea.index')`. Prefer (B) so layout stays generic: in the capture box section, when `isset($replyingTo) && $replyingTo`, add `@keydown.escape.window="$event.target.closest('form') && window.location.href = '{{ route('idea.index') }}'"` or use a dedicated handler that checks `document.activeElement` is inside the form and then redirects. Simplest: add a link or button that’s already “Cancel” and give it focus on Escape when in reply mode. Actually spec says “Escape to … cancel reply”. So when we’re on the page with replyingTo set, Escape should navigate to idea.index. We can do that in the layout: if Escape and window.location.search includes 'parent_id', navigate to idea.index. So in handleKey for Escape: after closing palette/search, if `window.location.search.includes('parent_id')` then `window.location = '{{ route('idea.index') }}'` (we need the route URL in JS; we can output it in a data attribute on the body or in the wrapper: `data-idea-index-url="{{ route('idea.index') }}"` and read it in handleKey). Implement that.

- [ ] **Step 3: Commit**

  Commit: `feat(shortcuts): Escape cancels reply (navigate to idea index)`

### Task 5.2: Final manual test checklist

- [ ] **Step 1: Run through all shortcuts**

  On idea index: ⌘/ focuses capture; ⌘K opens search; ? opens palette; Escape closes palette/search; in list, j/k move selection, Enter opens reply; when replying, Escape goes to index. In capture, ⌘+Enter submits. No shortcuts fire when typing in textarea or search input (except Escape and ⌘+Enter in capture).

- [ ] **Step 2: Commit any small fixes**

  If any bug fixes, commit: `fix(shortcuts): ...`

---

## Summary

| Chunk | Content |
|-------|---------|
| 1 | Layout: wrapper, global key handler, context guard, Escape, ⌘K, ⌘/, ?, nav link for palette |
| 2 | Layout: shortcut palette overlay and table, search hint |
| 3 | Idea index: focus-capture listener and ref, thought list x-data, thought-nav/thought-reply, selection UI and hint |
| 4 | Help route, controller, view with shortcut table, Help nav link |
| 5 | Escape cancel reply, final manual verification |

Plan complete. Execute in order; Chunks 1–2 are layout-only, Chunk 3 is index-only, Chunk 4 is new route/view, Chunk 5 is cross-cutting behaviour and QA.

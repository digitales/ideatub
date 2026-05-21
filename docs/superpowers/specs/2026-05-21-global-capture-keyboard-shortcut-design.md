# IdeaTub — Global Capture Keyboard Shortcut Design

**Date:** 2026-05-21  
**Status:** Approved (brainstorming)  
**Scope:** `layouts/idea.blade.php`, shared capture partial, `resources/js/app.js` (`captureBox`, `ideaShortcuts`)  
**Extends:** [2026-03-12-keyboard-shortcuts-design.md](./2026-03-12-keyboard-shortcuts-design.md) (⌘/ binding and layout handler)

## Overview

Users can capture a full-featured thought from any authenticated IdeaTub page that uses `layouts.idea` via the existing **⌘/** (Ctrl+/) shortcut. On the home page, **⌘/** continues to focus the inline capture textarea. On all other idea-layout pages, **⌘/** opens a global capture modal with the same capabilities as the home capture box (drafts, video URL mode, file import when enabled, inner focus mode). Captures are always top-level (no `parent_id`). After a successful save from the global modal, the user stays on the current page.

## Requirements (decisions)

| Topic | Decision |
|-------|----------|
| Capture richness | Full home capture box (not minimal quick-capture) |
| Page context | No auto-reply or project attach; always top-level |
| After save (global) | Stay on page; close modal; show success feedback |
| After save (home inline) | Unchanged (redirect to home index when applicable) |
| Shortcut binding | Reuse **⌘/** / **Ctrl+/** |
| Layout scope | `layouts.idea` only (not tools/auth/minimal) |

## Approaches considered

### 1. Shared partial + layout overlay (chosen)

Extract capture markup into `idea/partials/capture_box.blade.php`. Mount inline on home and inside a modal in the idea layout. Extend `captureBox()` with `placement: 'inline' | 'global'` for post-save behavior. **⌘/** on home focuses inline capture; elsewhere opens the global overlay.

**Pros:** Single UI and JS path; feature parity with home. **Cons:** Moderate refactor (partial extraction, layout shell, `submitCapture` branches).

### 2. Always use global overlay (including home)

**Rejected:** Removes always-visible home capture; larger UX change than needed.

### 3. Duplicate capture markup in layout

**Rejected:** Maintenance burden and feature drift.

---

## Architecture

### Global capture shell (`layouts.idea`)

- Add a capture host sibling to the shortcuts modal: backdrop + centred panel (`max-w-2xl`, `max-h-[85vh]`, scrollable).
- Alpine state `captureOpen` on `ideaShortcuts` (or a small nested shell component).
- Listen for `@ideatub-open-capture.window` to set `captureOpen = true` and focus the textarea on `$nextTick`.
- Include capture partial with `placement => 'global'` and the same `data-*` URLs as home (`thoughts.store`, drafts, videos, import routes when feature flags allow).
- **Do not render or open the global shell on `idea.index`** — only the inline capture box is mounted there (avoids duplicate `captureBox()` instances and draft conflicts).

### Shortcut handler (`ideaShortcuts.handleKey`)

Keep **⌘/** / **Ctrl+/** with existing input guard (no fire in `input`, `textarea`, `select`).

| Page | Action |
|------|--------|
| Home (`idea.index`) | `$dispatch('focus-capture')` — focus inline textarea (unchanged) |
| Other idea-layout pages | `$dispatch('ideatub-open-capture')` — open global modal |

If the shortcuts palette is open when **⌘/** is pressed: close the palette, then open capture (one action).

### Escape priority (when not typing in an input)

1. Close shortcuts palette (`shortcutsOpen`)
2. Close search overlay (`searching`)
3. Close global capture shell (`captureOpen`) — only if inner `captureBox` focus overlay is closed
4. Existing behaviour (e.g. cancel reply URL on home)

Inner capture **focus mode** (full-screen backdrop inside `captureBox`) is closed by `captureBox` first; the global shell does not close until focus mode is off.

### Events

| Event | Producer | Consumer |
|-------|----------|----------|
| `focus-capture` | `ideaShortcuts` (⌘/ on home) | Inline `captureBox` on `idea.index` |
| `ideatub-open-capture` | `ideaShortcuts` (⌘/ elsewhere) | Layout global shell |
| `ideatub-capture-saved` | `captureBox` (global, on success) | Layout shell closes modal |

---

## UI & markup

### Partial extraction

Move capture box markup from `resources/views/idea/index.blade.php` to `resources/views/idea/partials/capture_box.blade.php`.

Parameters:

- `placement`: `'inline'` | `'global'`
- `initialContent`, `forceVideoMode`, `importUploadsEnabled` (same logic as current home `@php` block)
- `replyingTo` / reply UI: **inline home only** when replying; global never passes reply context

Root element: `data-placement="{{ $placement }}"` plus existing `data-*` attributes.

### Global modal

- Header: “Capture thought” + close button
- `role="dialog"`, `aria-modal="true"`, labelled by header
- Reuse `ideatub-modal-backdrop` / `ideatub-modal-panel` (or equivalent tokens used by shortcuts palette)
- z-index consistent with existing modals (nav below modal)

### Home inline

`idea/index` `@include`s the partial with `placement => 'inline'`; visual appearance unchanged.

### Help & shortcut palette

Update the “Focus capture” row to describe dual behaviour, e.g.:

- **Quick capture** — **⌘/** or Ctrl+/
- On home: focus capture box
- Elsewhere: open capture anywhere

Apply the same copy on `resources/views/help.blade.php`.

---

## JavaScript (`captureBox`)

Read `data-placement` on init (`inline` default).

### `submitCapture` (text)

| | `inline` | `global` |
|--|----------|----------|
| Success navigation | Keep current redirect to `idea.index` when JSON includes `thought` | No `window.location` change |
| Reply handling | `appendCommentToParent` when `isReplyMode` | N/A |
| After success | Clear content, delete draft, `fetchDrafts()`, show message | Same + `$dispatch('ideatub-capture-saved')` |
| Shell close | N/A | Parent closes on `ideatub-capture-saved` (~1.5s after success message or immediately after brief success display) |

Chunked saves: show generic success (“Thought saved.”) and close; no redirect.

### `submitVideoCapture`

Global placement: skip `window.location = target`; dispatch `ideatub-capture-saved` on success.

### Drafts

Single draft API per user; global and home share drafts. Autosave behaviour unchanged.

### Import toolbar

Included in global partial when `features.file_upload` and routes are available (same conditions as home). Folder batch import may still navigate away after submit — acceptable v1 edge case.

### Unsaved close

No confirmation dialog in v1; draft autosave continues until content is cleared or saved.

### Optional page toast

v1 may use in-overlay success only. A future `ideatub-toast` event is not required for v1.

---

## Edge cases

- **Two capture instances on home:** Prevented by not mounting global shell on `idea.index`.
- **Palette + capture:** Closing palette before opening capture on **⌘/**.
- **419 / 503 / 422:** Existing inline errors; global overlay stays open.
- **Demo mode / import disabled:** Omit import `data-*` on global instance same as home.
- **Mobile:** No **⌘/** on mobile keyboards; no FAB in v1.

---

## Testing

### Automated

- Feature test: authenticated user, idea-layout page other than home (e.g. Stream) — assert response HTML contains global capture shell markup (e.g. `data-placement="global"` or `ideatub-open-capture` listener / dialog landmark).

### Manual checklist

- Stream (or memory): **⌘/** opens modal; Escape closes; **⌘+Enter** saves top-level thought; user remains on page.
- Video URL in global modal enters video mode and saves without navigation.
- Home: **⌘/** focuses inline textarea (no global modal).
- Help and shortcut palette show updated **⌘/** copy.

---

## Out of scope (v1)

- Context-aware reply or project attach from current page
- Shortcuts on `layouts.app`, auth, pricing, minimal share layouts
- User-configurable key bindings
- Nav “Capture” button
- Unsaved-close confirmation
- Guest / unauthenticated global capture

---

## File touch list (implementation reference)

| File | Change |
|------|--------|
| `resources/views/idea/partials/capture_box.blade.php` | **New** — extracted capture UI |
| `resources/views/idea/index.blade.php` | Include partial (`inline`) |
| `resources/views/layouts/idea.blade.php` | Global shell, `captureOpen`, event listeners, Escape order, shortcut palette copy |
| `resources/js/app.js` | `captureBox` placement branch; `ideaShortcuts` ⌘/ routing |
| `resources/views/help.blade.php` | Updated shortcut table row |
| `tests/Feature/...` | Assert global capture present on non-home idea pages |

---

## Related documents

- [2026-03-12-keyboard-shortcuts-design.md](./2026-03-12-keyboard-shortcuts-design.md)
- [2026-03-12-keyboard-shortcuts.md](../plans/2026-03-12-keyboard-shortcuts.md) (original implementation plan)

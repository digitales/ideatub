# IdeaTub — Keyboard Shortcuts Design

**Date:** 2026-03-12  
**Status:** Approved  
**Scope:** IdeaTub thinking space only (`layouts/idea.blade.php`, `resources/views/idea/index.blade.php`)

## Overview

Add keyboard shortcuts to make navigating the IdeaTub interface faster. Use a single document-level key handler in the idea layout (Alpine.js), with context guards so shortcuts do not fire while the user is typing in inputs. Surface shortcuts via inline hints, an in-app shortcut palette (triggered by `?` or a nav link), and documentation on the Help page.

---

## 1. Key bindings

| Action | Shortcut (Mac) | Shortcut (Win/Linux) |
|--------|----------------|----------------------|
| Focus capture (textarea) | ⌘/ | Ctrl+/ |
| Open / focus search | ⌘K | Ctrl+K |
| Move to next thought | j or ↓ | j or ↓ |
| Move to previous thought | k or ↑ | k or ↑ |
| Open reply for selected thought | Enter | Enter |
| Cancel reply / close search | Escape | Escape |
| Show shortcut palette | ? | ? |
| Submit thought (existing) | ⌘+Enter | Ctrl+Enter |

`⌘K` is reserved for “Find a memory” on the idea layout. `?` opens the palette only when focus is not in an input/textarea.

---

## 2. Context guards

- **Single handler:** One `@keydown.window` (or equivalent) on the idea layout handles all shortcuts.
- **Skip when typing:** If focus is in an `input`, `textarea`, or `select`, do **not** run: ⌘/, ⌘K, j, k, Enter (for reply), ?.
- **Always run:** Escape (close search, cancel reply, or blur); ⌘+Enter in capture continues to submit the form via existing handler.
- **Implementation:** e.g. “If `document.activeElement` is input/textarea/select and key is not Escape, return and do nothing.”

---

## 3. Shortcut palette (in-app)

- **Trigger:** `?` (Shift+/) when focus is not in an input/textarea; plus a “Keyboard shortcuts” link in the nav that opens the same palette.
- **Content:** Modal/overlay listing the same actions as in the table above (including “Submit thought — ⌘+Enter” and “Show this list — ?”).
- **Behaviour:** Click outside or Escape to close. Implemented with Alpine (`x-data`, `x-show`) in the idea layout.
- **Styling:** Match existing idea layout (e.g. same card/overlay style as the capture box).

---

## 4. Inline hints

- **Capture:** Keep “⌘ + Enter to store”. Optionally add “⌘/ to focus” near the textarea (e.g. same hint line or under the Store button).
- **Search:** When search overlay is open: e.g. “Escape to close · ⌘K to focus search”. When closed: optional hint on the “Find a memory” pill (e.g. “⌘K”).
- **Thought list:** When “selected thought” (j/k) exists: one-line hint above or below the list, e.g. “↑↓ or j/k to move · Enter to reply”.

---

## 5. Help page

- **Where:** Existing “Help” in the nav (currently `#`). Point to a new route (e.g. `/help`) or in-app Help section.
- **Content:** Same shortcut table as in this doc and in the palette, so Help is the durable reference.

---

## 6. Implementation approach

- **Approach:** Alpine + one document-level key listener (no new JS bundle).
- **Location:** Logic in idea layout and idea index view; palette and “selected thought” state in Alpine.
- **List navigation:** Thoughts in the list get a “selected” index (0-based); j/k or ↑/↓ update it and optionally scroll into view; Enter navigates to reply URL for the selected thought. Selection only when focus is not in input/textarea.

---

## 7. Out of scope (for later)

- Shortcuts on auth, tools, pricing, or other layouts.
- Remapping or user-configurable shortcuts.

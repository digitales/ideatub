# Capture focus mode not filling the window — Customer Support Investigation

**Date**: 2026-05-18  
**Status**: Resolved  
**Priority**: Medium  
**Reported By**: Internal (support)

## Issue Description

On the home capture box, opening **Focus** mode showed the draft/capture UI as a narrow panel on the left with empty space to the right, instead of a full-viewport overlay with a centered writing surface.

## Customer Impact

- Distraction-free capture mode felt broken on wide screens and in dark mode.
- Same `ideatub-focus-shell` class is used when editing thought content on cards.

## Root Cause Analysis

Focus mode relied on Alpine `:class` to add Tailwind utilities (`fixed inset-0 z-50 flex …`) when `focusOverlayOpen` was true, while base layout classes (`ideatub-surface`, `max-w-[600px]`) stayed on the same node.

The overlay did not reliably cover the viewport: positioning lived in dynamic utility classes instead of the dedicated `.ideatub-focus-shell` component styles, and the inner panel kept a `max-w-[600px]` constraint even in focus mode. After the visual refresh (`max-w-2xl` page column) and dark-mode focus tokens, the shell could appear as a left-aligned card over the page background rather than a full-screen layer.

## Resolution

1. **`resources/css/app.css`** — Moved full-viewport layout into `.ideatub-focus-shell` (`position: fixed`, `inset: 0`, `width: 100vw`, viewport breakout via `left: 50%` / `margin-left: -50vw`, `items-center`). Updated `.ideatub-focus-panel` to center content (`mx-auto`, `max-w-3xl`).
2. **`resources/views/idea/index.blade.php`** — Toggle only `ideatub-focus-shell` from Alpine; drop inline `max-w-[600px]` when focus is open.
3. **`resources/js/app.js`** — Added `closeFocusOverlay()` (body scroll unlock, focus return); `toggleFocus()` locks body scroll; Escape uses `closeFocusOverlay()`.
4. **`resources/views/idea/partials/editable_thought_content.blade.php`** — Same `ideatub-focus-shell` class change for in-card edit focus mode.

## Prevention & Follow-up

- [ ] Prefer component classes (`.ideatub-focus-shell`) for modal/overlay layout instead of Alpine-only Tailwind utilities.
- [ ] Manual check: home → Focus → full viewport background; Close / Escape / backdrop click; dark mode.

## References

- Design: `docs/superpowers/specs/2026-03-17-thought-drafts-and-focus-design.md`
- Capture markup: `resources/views/idea/index.blade.php`
- Focus tokens: `resources/css/app.css` (`.ideatub-focus-*`)

# Search bar overlapping browser chrome — Customer Support Investigation

**Date**: 2026-03-16  
**Status**: Resolved  
**Priority**: Medium  
**Reported By**: Customer  

## Issue Description

When the search overlay was active (⌘K / “Find a memory”), the search bar extended into the top of the window and overlapped the browser chrome (e.g. macOS traffic lights / title bar), making the UI feel broken and obscuring system controls.

## Customer Impact

- Users who use the in-app search (⌘K), especially in standalone/PWA or notched/full-screen contexts
- Medium severity: search works but layout overlaps system UI and looks broken

## Investigation Steps

1. Located search overlay in `resources/views/layouts/idea.blade.php` (idea layout).
2. Confirmed overlay is `absolute inset-x-0 top-0 bottom-0` inside the sticky nav; nav uses `sticky top-0`, so it (and the overlay) sit at the viewport top.
3. Identified that no safe-area or top inset was applied, so in environments where content can draw under the browser chrome (PWA, standalone, notched devices), the search bar overlapped that area.

## Root Cause Analysis

The sticky nav and the search overlay were aligned to the viewport top (`top-0`) with no respect for `env(safe-area-inset-top)`. In viewports that extend under the browser chrome or system UI, the first paint area starts at 0, so the nav and overlay visually overlapped the chrome.

## Resolution

1. **Body top padding**  
   In `resources/views/layouts/idea.blade.php`, added to `<body>`:
   - `padding-top: env(safe-area-inset-top, 0px);`  
   So the whole page (including the sticky nav and search overlay) starts below the safe area when the browser reports one.

2. **Viewport meta**  
   Set viewport to include `viewport-fit=cover` so supported browsers (e.g. PWA, standalone, notched devices) report safe-area insets and `env(safe-area-inset-top)` is used where appropriate.

No change to the overlay’s own positioning; it remains full-height within the nav, which is now laid out below the safe area.

## Prevention & Follow-up

- [ ] Consider a shared layout or CSS variable for “app top inset” so other layouts (e.g. `app.blade.php`) stay consistent.
- [ ] If overlap is still reported on specific browsers, capture UA and viewport details and re-check `env(safe-area-inset-top)` support.

## Related Issues

- `support/2026-03-13-search-input-background.md` — previous search overlay UX fix (input visibility).

## References

- Layout: `resources/views/layouts/idea.blade.php` (body style, viewport meta, nav and search overlay)
- MDN: [env()](https://developer.mozilla.org/en-US/docs/Web/CSS/env), [viewport-fit](https://developer.mozilla.org/en-US/docs/Web/HTML/Viewport_meta_tag#viewport-fit)

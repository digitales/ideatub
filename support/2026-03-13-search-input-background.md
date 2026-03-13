# Search text input background not visible — Customer Support Investigation

**Date**: 2026-03-13  
**Status**: Resolved  
**Priority**: Low  
**Reported By**: Internal  

## Issue Description

When using the search overlay (⌘K / Ctrl+K), the search text input had the same background as the overlay, making it hard to see where to type. The input used `bg-transparent`, so it blended with the page/overlay background.

## Customer Impact

- All users using the in-app search overlay
- Low severity: search still worked; UX was confusing (input area not visually distinct)

## Investigation Steps

1. Located search overlay in `resources/views/layouts/idea.blade.php` (idea layout).
2. Identified the search `<input>` with `class="... bg-transparent ..."`.
3. Overlay uses `background: rgba(238,242,255,0.95)` — input inherited that look via transparency.

## Root Cause Analysis

The search input was intentionally styled with `bg-transparent` and `border-none`, causing it to blend with the overlay. No explicit white (or contrasting) background was set for the input.

## Resolution

Updated the search input in `resources/views/layouts/idea.blade.php`:

- **Background**: `bg-transparent` → `bg-white` so the input has a clear white background.
- **Shape**: Added `rounded-md px-3 py-2` for a clear input box.
- **Border**: Replaced `border-none` with `border border-slate-200/80` for a visible edge.
- **Focus**: Added `focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50` for accessible focus state.

## Prevention & Follow-up

- Consider design tokens for “input on overlay” so form fields on overlays always have a contrasting background.

## References

- Layout: `resources/views/layouts/idea.blade.php` (search overlay and input)

# Project “Add thought” select overflows card — Customer Support Investigation

**Date**: 2026-04-13  
**Status**: Resolved  
**Customer**: Internal / UX report (screenshot)  
**Priority**: Low  
**Reported By**: Internal

## Issue Description

On the project detail page (`projects.show`), the thought picker (`<select>`) and “Add” button sit in a flex row on wider breakpoints. The dropdown grew wider than the white card: long option labels made the native `<select>` use a large intrinsic minimum width, so the control and button extended past the rounded container.

## Customer Impact

- Visual breakage on project pages when choosing thoughts with long content in the dropdown.
- “Add” could appear disconnected from the card (pushed to the right).

## Investigation Steps

1. Located UI in `resources/views/projects/show.blade.php` — form uses `flex flex-col sm:flex-row` with `flex-1` on the `<select>`.
2. Confirmed root cause: flex items default to `min-width: auto`, so the select does not shrink below the width implied by its options (browser-dependent).
3. Applied standard fix: `min-w-0` on the flex container chain and the select, plus `w-full` / `flex-1` on the select and `shrink-0` on the button.

## Root Cause Analysis

CSS Flexbox: the `<select>` is a flex child with `flex-1` but without `min-width: 0` (Tailwind `min-w-0`), its minimum size is the content’s intrinsic minimum (longest option text), so it overflowed the parent instead of sharing space with the button.

## Resolution

- Updated the add-thought form in `resources/views/projects/show.blade.php`:
  - Form: `min-w-0` so the flex formatting context can shrink inside the card.
  - Select: `min-w-0 w-full flex-1` so it respects the card width on `sm:flex-row`.
  - Submit button: `shrink-0` so it keeps a stable width and stays in the layout flow.

## Customer Communication

- N/A (internal fix).

## Prevention & Follow-up

- [ ] When pairing native `<select>` with flex row layouts, default to `min-w-0` on the select or a wrapping `div.flex-1.min-w-0`.
- [ ] Option labels already use `Str::limit(..., 80)` in the view; if overflow reports continue, consider shorter limits or a custom combobox for very long titles.

## Related Issues

- None filed.

## Lessons Learned

Native form controls in flex rows often need explicit `min-width: 0` on the growing child; `flex-1` alone is not enough.

## References

- `resources/views/projects/show.blade.php`

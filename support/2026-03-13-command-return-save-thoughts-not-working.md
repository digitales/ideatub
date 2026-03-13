# Command+Return / Ctrl+Enter not saving thoughts — Customer Support Investigation

**Date**: 2026-03-13  
**Status**: Resolved  
**Priority**: High  
**Reported By**: Customer (support)

## Issue Description

Customer reported that **⌘+Return** (Mac) was not saving thoughts from the capture box. The UI and help text state "⌘+Enter or Ctrl+Enter" to submit a thought, but the shortcut had no effect when focus was in the textarea.

## Customer Impact

- Users could not use the advertised keyboard shortcut to save thoughts.
- Workaround was to click "Store thought" or submit the form another way.

## Investigation Steps

1. **Reviewed shortcut binding**: The submit shortcut was bound only on the **form** in `resources/views/idea/index.blade.php`:
   - `@keydown.meta.enter.prevent="submitCapture()"` on the `<form>`.
2. **Event flow**: When the user focuses the **textarea** and presses Cmd+Enter, the `keydown` event fires on the textarea and bubbles. In theory the form should receive it and run `submitCapture()`.
3. **Global shortcuts**: The idea layout uses `@keydown.window="handleKey($event)"` in `ideaShortcuts()`. When focus is in an input/textarea, `handleKey` returns early for any key except Escape (`if (inInput && e.key !== 'Escape') return;`), so it does not prevent or handle Cmd+Enter.
4. **Known pattern**: Alpine.js and DOM best practice for "submit on Cmd+Enter in textarea" is to attach the keydown handler **on the textarea** itself, so the event is handled at the element that has focus, rather than relying on bubbling to the form. See e.g. [Submitting Forms With CMD+Enter In Alpine.js](https://www.bennadel.com/blog/4596-submitting-forms-with-cmd-enter-in-alpine-js.htm).

## Root Cause Analysis

- The handler was on the **form**, while focus was in the **textarea**. Depending on browser, event delegation, or focus/stack order, the keydown might not have been consistently delivered to the form or the modifier check might not have matched when the event was handled at the form level.
- **Ctrl+Enter** (Windows/Linux) was not bound at all; only `@keydown.meta.enter` existed, so the documented "Ctrl+Enter" would not work on non-Mac platforms.

## Resolution

1. **Bind Cmd+Enter / Ctrl+Enter on the textarea** in `resources/views/idea/index.blade.php`:
   - Added `@keydown.meta.enter.prevent="submitCapture()"` and `@keydown.ctrl.enter.prevent="submitCapture()"` on the capture `<textarea>`.
   - The textarea lives inside the `x-data="captureBox()"` div, so `submitCapture()` is in scope.
2. **Left the form-level** `@keydown.meta.enter.prevent="submitCapture()"` in place for redundancy (e.g. if focus is elsewhere in the form).

Result: The shortcut is handled where focus is (textarea), and both ⌘+Enter (Mac) and Ctrl+Enter (Windows/Linux) are supported.

## Prevention & Follow-up

- [ ] For "submit on modifier+Enter" in a textarea, attach the keydown listener on the **textarea** (and support both `.meta.enter` and `.ctrl.enter`) rather than only on the form.
- [ ] When documenting shortcuts that differ by OS (⌘ vs Ctrl), ensure the implementation actually binds both modifiers.

## Store button does nothing (no event, no page load)

When the "Store thought" button was clicked with an **empty** textarea, nothing appeared to happen. Cause: the textarea had the HTML5 `required` attribute. The browser therefore blocked the form’s submit event (validation ran first), so the form never fired `submit` and Alpine’s `@submit.prevent="submitCapture()"` never ran — no feedback and no request.

**Fixes:**
- Removed the `required` attribute from the capture textarea so the submit event always fires. Validation is still enforced in `submitCapture()` and on the server.
- When the user tries to submit with no text, `submitCapture()` now sets a visible message: "Add some text to save." (shown for 3 seconds).

## Additional finding: IdeaController::index() return type

Server logs showed: `IdeaController::index(): Return value must be of type Illuminate\View\View|Illuminate\Http\RedirectResponse, Illuminate\Http\JsonResponse returned`. The index action returns JSON for AJAX requests (e.g. search “load more”), but the method’s return type did not include `JsonResponse`. That caused a PHP type error (500) when those requests ran. **Fix:** Extended the declared return type to `View|RedirectResponse|JsonResponse` in `app/Http/Controllers/IdeaController.php`.

## Related Issues

- [2026-03-13-store-thought-button-not-functioning-after-ajax-change.md](2026-03-13-store-thought-button-not-functioning-after-ajax-change.md) — Store thought button / AJAX and layout fixes.

## References

- Capture form and textarea: `resources/views/idea/index.blade.php`
- `captureBox()` and `submitCapture()`: `resources/js/app.js`
- Help/shortcuts text: `resources/views/layouts/idea.blade.php`, `resources/views/help.blade.php`

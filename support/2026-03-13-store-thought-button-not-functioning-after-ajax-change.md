# Store thought button not functioning after AJAX change — Customer Support Investigation

**Date**: 2026-03-13  
**Status**: Resolved  
**Priority**: High  
**Reported By**: Customer (support)

## Issue Description

After a change to the AJAX handling, the "Store thought" button on the idea index page stopped functioning: either nothing happened on click, or the thought was saved but the list did not update.

## Customer Impact

- Users could not reliably store thoughts from the main capture box.
- When the server returned a non-JSON response (e.g. redirect), the client showed "Thought saved." but the new thought did not appear in the list until a manual refresh.

## Investigation Steps

1. Reviewed the store flow: form in `resources/views/idea/index.blade.php` uses `@submit.prevent="submitCapture()"` and Alpine.js `captureBox()` in `resources/js/app.js` submits via `fetch()` with `Accept: application/json` and `X-Requested-With: XMLHttpRequest`.
2. Confirmed `IdeaController@store` returns JSON when `$request->expectsJson()` is true (based on those headers).
3. Identified two possible failure modes:
   - **FormData / textarea sync**: `FormData(form)` is built from the form DOM. If Alpine’s `x-model="content"` had not synced the textarea’s value to the DOM before submit, the request body could have empty or stale `content`.
   - **Non-JSON response**: If the server returned a redirect (e.g. Laravel did not treat the request as JSON), `fetch()` would follow the redirect and the final response could be 200 HTML. `res.json()` would fail, `data` would be `{}`, and the client would set `message = 'Thought saved.'` but never run `window.location` (because `data.thought` was missing), so the list would not refresh.

## Root Cause Analysis

- **Cause 1**: Relying on the textarea’s DOM value for `FormData(form)` without explicitly syncing Alpine’s `content` to the textarea before building the body could, in some timing or binding cases, result in empty or incorrect content being sent.
- **Cause 2**: When the server responded with HTML (e.g. redirect) instead of JSON, the client treated the request as successful (e.g. 200 after redirect) but had no `data.thought`, so it did not redirect or refresh the list, making it appear that the button did not work.

## Resolution

### 1. Sync textarea value before building FormData (`resources/js/app.js`)

Before creating `FormData(form)`, the textarea’s value is now set explicitly from Alpine’s `content` so the submitted body always contains the current text:

```javascript
// Ensure textarea value is in sync with Alpine model before building FormData
const textarea = form.querySelector('[name="content"]');
if (textarea) textarea.value = content;
```

### 2. Fallback when response is success but not JSON (`resources/js/app.js`)

When the response is OK but `data.thought` is missing (e.g. server returned HTML, so `res.json()` failed and `data` is `{}`), the client now redirects to the idea index so the list refreshes and the new thought appears:

```javascript
if (data.thought) {
  // ... existing branch for comment vs top-level
} else {
  // Server may have returned HTML (e.g. redirect); refresh so the list updates
  window.location = this.$el.dataset.ideaIndexUrl || window.location.pathname;
}
```

## Prevention & Follow-up

- [ ] When using `fetch()` with form data and a framework model (e.g. Alpine `x-model`), explicitly set the form field values from the model before building `FormData(form)` to avoid sync issues.
- [ ] For endpoints that can return either JSON or redirect, client should handle both: on success without a JSON body, consider redirecting or reloading so the UI reflects the new state.

## Related Issues

- [2026-03-12-textarea-htmlobject-and-nav-support.md](2026-03-12-textarea-htmlobject-and-nav-support.md) — previous capture box / textarea fix.

## References

- Capture box and store flow: `resources/views/idea/index.blade.php`, `resources/js/app.js` (Alpine `captureBox`)
- Store endpoint: `app/Http/Controllers/IdeaController.php` `store()` method, route `thoughts.store`

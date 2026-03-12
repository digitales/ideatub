# Store thought textarea showing [object HTMLTextAreaElement] and top nav missing — Customer Support Investigation

**Date**: 2026-03-12  
**Status**: Resolved  
**Priority**: High  
**Reported By**: Customer (support)

## Issue Description

1. The store-thought textarea was displaying the literal string `[object HTMLTextAreaElement]` instead of user content.
2. The top navigation was reported missing (user said they were missing the top navigation).

## Root Cause Analysis

### [object HTMLTextAreaElement] in textarea

- The string `[object HTMLTextAreaElement]` is what JavaScript produces when an HTML element is coerced to a string (e.g. `String(textareaElement)`).
- The capture box uses Alpine.js with `x-model="content"` on the textarea and a window listener `@focus-capture.window="$refs.captureTextarea?.focus()"`.
- **Hypothesis:** In some Alpine or event-handling paths, the inline expression `$refs.captureTextarea?.focus()` could be evaluated in a context where the ref (the textarea element) was assigned or bound into the same scope as `content`, or the event handling caused the bound variable to receive the element reference. Once `content` held the element, `x-model` would display it as that string.
- **Additional factor:** The textarea also had server-rendered `{{ old('content') }}` inside its body. If a previous submit had sent the string `[object HTMLTextAreaElement]` (from a time when `content` was the element), that value would be flashed back via `old('content')`, reinforcing the issue.

### Top navigation missing

- The idea layout shows the nav (logo, search, Help, Keyboard shortcuts, Find a memory, avatar) inside a wrapper that uses Alpine `ideaShortcuts`. The right-side nav items use `x-show="!searching"`.
- If Alpine fails to initialize (e.g. JS error in `ideaShortcuts`, or missing view data such as `$query`), the wrapper or bindings may not run correctly and the nav can appear broken or missing.
- Views that extend `layouts.idea` must pass `query` (or ensure `$query ?? ''` is defined) so the layout does not throw when rendering.

## Resolution

### 1. Textarea fix (resources/views/idea/index.blade.php)

- **Replaced** the inline focus-capture handler  
  `@focus-capture.window="$refs.captureTextarea?.focus()"`  
  with a dedicated method that only focuses the element and does not touch `content`:
  - `x-data` now includes `focusCapture() { const el = this.$refs.captureTextarea; if (el && el.focus) el.focus(); }`
  - Listener is now `@focus-capture.window="focusCapture()"`.
- **Removed** server-rendered `{{ old('content') }}` from inside the textarea body. The only initial value is now Alpine’s `content: @json(...)`, so there is a single source of truth.
- **Sanitize old input:** `content` is now `@json((old('content') === '[object HTMLTextAreaElement]' ? '' : old('content', '')))` so that if that string was ever submitted and flashed back via `old('content')`, it is never shown in the textarea.

### 2. Nav visibility (resources/views/layouts/idea.blade.php)

- **Cause:** The right nav used `x-show="!searching"`. Before Alpine initializes (or if it fails), `x-show` can leave the element hidden, so the nav never appeared.
- **Fix:** Replaced `x-show="!searching"` with `:class="{ 'hidden': searching }"` on the right nav container. Without Alpine the container has no `hidden` class and is visible; once Alpine runs it only adds `hidden` when `searching` is true.

## Prevention & Follow-up

- [ ] Avoid inline window listeners that reference `$refs` in the same component as `x-model`; use a method that only performs the side effect (e.g. focus) and does not assign to the model.
- [ ] Use a single source of truth for textarea initial value (Alpine state from `old('content')`) and do not duplicate it in the textarea body.

## References

- Keyboard shortcuts implementation: `docs/superpowers/specs/2026-03-12-keyboard-shortcuts-design.md`
- Idea layout: `resources/views/layouts/idea.blade.php`
- Idea index (capture box): `resources/views/idea/index.blade.php`

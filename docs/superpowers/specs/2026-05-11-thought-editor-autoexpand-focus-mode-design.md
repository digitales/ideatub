# Thought Editor: Auto-Expand & Focus Mode

**Date**: 2026-05-11
**Status**: Draft

## Problem

When editing a thought's content, the textarea is fixed at `rows="4"` with no auto-resize. Long content is hidden behind a tiny editing window. There is no way to enter a distraction-free editing mode for thought content, even though the capture box on the homepage already has a focus mode.

## Solution

Extend the `thoughtContentEditor` Alpine component with two capabilities:

1. **Auto-expanding textarea** -- the editing textarea grows to fit all content.
2. **Focus mode** -- an optional full-viewport overlay for distraction-free editing, mirroring the capture box's existing focus mode pattern.

## Approach

Extend `thoughtContentEditor` inline (Approach A). Two files change: `resources/js/app.js` and `resources/views/idea/partials/editable_thought_content.blade.php`.

## Design

### Auto-Expanding Textarea

Add a `resizeTextarea()` method to `thoughtContentEditor`:

```js
resizeTextarea() {
    const textarea = this.$refs.editTextarea;
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}
```

Call sites:
- `startEdit()` via `$nextTick` (size to existing content on edit entry)
- `@input` event on the textarea (grow as user types)
- After exiting focus mode (content reflows at different width)

Textarea styling changes:
- Add `resize-none overflow-hidden` to prevent manual resize handle and scrollbars.
- Keep `rows="4"` as a minimum height fallback. The auto-resize overrides it immediately on `startEdit()`, but the rows attribute prevents a zero-height flash before `$nextTick` fires.
- Add `x-ref="editTextarea"` to both textarea instances in the Blade partial.

No max-height constraint in normal inline mode. The textarea shows all content and pushes surrounding cards down.

### Focus Mode

Mirror the capture box's `focusOverlayOpen` pattern from `captureBox`.

**New state in `thoughtContentEditor`:**
- `focusOverlayOpen: false`

**New methods:**

```js
toggleFocus() {
    this.focusOverlayOpen = !this.focusOverlayOpen;
    if (this.focusOverlayOpen) {
        document.body.style.overflow = 'hidden';
        this.$nextTick(() => this.$refs.editTextarea?.focus());
    } else {
        document.body.style.overflow = '';
        this.$nextTick(() => this.resizeTextarea());
    }
}
```

**Updated methods:**
- `cancelEdit()` -- close focus mode if open, restore body scroll.
- `saveEdit()` -- close focus mode if open, restore body scroll (after successful save).
- `destroy()` -- restore body scroll if focus was open when component is torn down.

**Escape key priority:**
The editing div already has `@keydown.escape.stop.prevent="cancelEdit()"`. Change this to: first Escape closes focus mode (if open), second Escape cancels editing.

```js
handleEditEscape() {
    if (this.focusOverlayOpen) {
        this.toggleFocus();
    } else {
        this.cancelEdit();
    }
}
```

The Blade partial's `@keydown.escape` handler calls `handleEditEscape()` instead of `cancelEdit()` directly.

### Blade Partial Changes (`editable_thought_content.blade.php`)

Both editing branches (the `detailMarkdownRead` branch at line 47-58 and the non-detail branch at line 110-119) get the same treatment:

**Wrapper div** (the existing `<div class="mb-2">` around the textarea):
```html
:class="focusOverlayOpen ? 'fixed inset-0 z-50 flex flex-col p-6' : 'mb-2'"
```

**White backdrop** (inside the wrapper, before textarea):
```html
<div
    x-show="focusOverlayOpen"
    x-cloak
    @click="toggleFocus()"
    class="absolute inset-0 bg-white -z-10"
    aria-hidden="true"
></div>
```

**Inner container** (wraps textarea + toolbar when focused):
```html
:class="focusOverlayOpen ? 'max-w-4xl w-full mx-auto flex flex-col flex-1 min-h-0' : ''"
```

**Textarea** additions:
- `x-ref="editTextarea"`
- `@input="resizeTextarea()"`
- Class additions: `resize-none overflow-hidden`
- Focus-mode class: `:class="focusOverlayOpen ? 'flex-1 min-h-0' : ''"`

**Toolbar** (Save/Cancel buttons row) gains the Focus/Close button:
```html
<button
    type="button"
    x-show="!focusOverlayOpen"
    @click="toggleFocus()"
    class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
>Focus</button>
<button
    type="button"
    x-show="focusOverlayOpen"
    x-cloak
    @click="toggleFocus()"
    class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo"
>Close</button>
```

**Escape handler** update:
```html
x-on:keydown.escape.stop.prevent="handleEditEscape()"
```

## Files Changed

| File | Change |
|------|--------|
| `resources/js/app.js` | `thoughtContentEditor`: add `focusOverlayOpen`, `toggleFocus()`, `resizeTextarea()`, `handleEditEscape()`. Update `startEdit()`, `cancelEdit()`, `saveEdit()`, `destroy()`. |
| `resources/views/idea/partials/editable_thought_content.blade.php` | Both editing branches: add `x-ref`, `@input`, focus overlay markup, Focus/Close button, updated escape handler. |

## Edge Cases

- **Save/cancel while focused**: Both methods close focus mode and restore body scroll before transitioning to read mode.
- **Multiple cards**: Each card has its own component instance with independent `focusOverlayOpen` state. The z-50 overlay isolates the focused card.
- **Detail page (`detailMarkdownRead`)**: Same behavior. The markdown prose view is replaced by the textarea on edit; focus mode works identically.
- **Non-editable thoughts**: No changes. Focus button and auto-resize only appear when `editable` and `editing` are both true.
- **No new keyboard shortcut**: The capture box's Cmd+/ targets the capture textarea specifically. Editing focus mode is toggled via the button only.
- **Component teardown**: `destroy()` restores body scroll if focus was open.

## Not In Scope

- Auto-expanding the capture box textarea (it already uses `flex-1` in focus mode; no change requested).
- New global keyboard shortcuts for editing focus mode.
- Changes to the thought detail page layout or markdown rendering.

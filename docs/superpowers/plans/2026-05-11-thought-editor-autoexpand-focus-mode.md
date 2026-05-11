# Thought Editor: Auto-Expand & Focus Mode — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the thought content editing textarea auto-expand to fit all content, and add an optional full-viewport focus mode mirroring the capture box pattern.

**Architecture:** Extend the existing `thoughtContentEditor` Alpine component with `resizeTextarea()`, `focusOverlayOpen` state, `toggleFocus()`, and `handleEditEscape()`. Update the `editable_thought_content.blade.php` partial to add focus overlay markup, auto-resize wiring, and Focus/Close buttons. No new files.

**Tech Stack:** Alpine.js, Blade, Tailwind CSS

---

### Task 1: Add `resizeTextarea()` to `thoughtContentEditor`

**Files:**
- Modify: `resources/js/app.js:771-932` (the `thoughtContentEditor` Alpine component)

- [ ] **Step 1: Add `resizeTextarea()` method**

In `resources/js/app.js`, inside the `thoughtContentEditor` component object (after the `startEdit()` method around line 871), add:

```js
resizeTextarea() {
    const textarea = this.$refs.editTextarea;
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
},
```

- [ ] **Step 2: Update `startEdit()` to call `resizeTextarea()`**

In `resources/js/app.js`, find the existing `startEdit()` method (around line 871):

```js
startEdit() {
    if (!this.editable) return;
    this.editing = true;
    this.draftContent = this.originalContent;
    this.error = '';
    this.$nextTick(() => this.$el.querySelector('textarea')?.focus());
},
```

Replace it with:

```js
startEdit() {
    if (!this.editable) return;
    this.editing = true;
    this.draftContent = this.originalContent;
    this.error = '';
    this.$nextTick(() => {
        const textarea = this.$refs.editTextarea;
        if (textarea) {
            textarea.focus();
            this.resizeTextarea();
        }
    });
},
```

- [ ] **Step 3: Verify auto-resize works in the browser**

Open the app, navigate to a thought with multi-line content (home page card or detail page). Click the `⋮` menu > Edit. The textarea should expand to show all content without scrollbars. Type additional lines — the textarea should grow. Delete lines — it should shrink.

- [ ] **Step 4: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: add resizeTextarea to thoughtContentEditor Alpine component"
```

---

### Task 2: Wire auto-resize into the Blade partial

**Files:**
- Modify: `resources/views/idea/partials/editable_thought_content.blade.php:47-58,110-119`

- [ ] **Step 1: Update the detailMarkdownRead editing branch (lines 47-58)**

Find the existing editing div and textarea (lines 47-52):

```html
        <div
            class="mb-2"
            x-show="editing"
            x-on:keydown.escape.stop.prevent="cancelEdit()"
        >
            <textarea x-model="draftContent" rows="4" class="{{ $editorClass }}"></textarea>
```

Replace with:

```html
        <div
            class="mb-2"
            x-show="editing"
            x-on:keydown.escape.stop.prevent="cancelEdit()"
        >
            <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden"></textarea>
```

- [ ] **Step 2: Update the non-detail editing branch (lines 110-112)**

Find the existing editing template and textarea (lines 110-112):

```html
        <template x-if="editing">
            <div class="mb-2" x-on:keydown.escape.stop.prevent="cancelEdit()">
                <textarea x-model="draftContent" rows="4" class="{{ $editorClass }}"></textarea>
```

Replace with:

```html
        <template x-if="editing">
            <div class="mb-2" x-on:keydown.escape.stop.prevent="cancelEdit()">
                <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden"></textarea>
```

- [ ] **Step 3: Verify both branches in the browser**

1. **Card editing (home page):** Edit a thought from the home page card list. Textarea should auto-expand.
2. **Detail page editing:** Navigate to a thought's detail page (`/thoughts/{id}`). Click "Edit". Textarea should auto-expand.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/editable_thought_content.blade.php
git commit -m "feat: wire auto-resize textarea into editable thought content partial"
```

---

### Task 3: Add focus mode state and methods to `thoughtContentEditor`

**Files:**
- Modify: `resources/js/app.js:771-932` (the `thoughtContentEditor` Alpine component)

- [ ] **Step 1: Add `focusOverlayOpen` state**

In `resources/js/app.js`, inside the `thoughtContentEditor` component's state declarations (after `editing: false,` around line 791), add:

```js
focusOverlayOpen: false,
```

- [ ] **Step 2: Add `toggleFocus()` method**

After the `resizeTextarea()` method added in Task 1, add:

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
},
```

- [ ] **Step 3: Add `handleEditEscape()` method**

After `toggleFocus()`, add:

```js
handleEditEscape() {
    if (this.focusOverlayOpen) {
        this.toggleFocus();
    } else {
        this.cancelEdit();
    }
},
```

- [ ] **Step 4: Update `cancelEdit()` to close focus mode**

Find the existing `cancelEdit()` (around line 879):

```js
cancelEdit() {
    this.editing = false;
    this.draftContent = this.originalContent;
    this.error = '';
},
```

Replace with:

```js
cancelEdit() {
    if (this.focusOverlayOpen) {
        this.focusOverlayOpen = false;
        document.body.style.overflow = '';
    }
    this.editing = false;
    this.draftContent = this.originalContent;
    this.error = '';
},
```

- [ ] **Step 5: Update `saveEdit()` to close focus mode on success**

Find the success path in `saveEdit()` (around line 916-925), the block after `if (!res.ok)` that sets `this.editing = false`:

```js
      this.content = data.content ?? trimmed;
      this.originalContent = this.content;
      this.draftContent = this.content;
      if (this.detailMarkdownRead && typeof data.content_html === 'string') {
        const el = this.$refs.markdownReadBody;
        if (el) {
          el.innerHTML = data.content_html;
        }
      }
      this.editing = false;
```

Replace with:

```js
      this.content = data.content ?? trimmed;
      this.originalContent = this.content;
      this.draftContent = this.content;
      if (this.detailMarkdownRead && typeof data.content_html === 'string') {
        const el = this.$refs.markdownReadBody;
        if (el) {
          el.innerHTML = data.content_html;
        }
      }
      if (this.focusOverlayOpen) {
        this.focusOverlayOpen = false;
        document.body.style.overflow = '';
      }
      this.editing = false;
```

- [ ] **Step 6: Add cleanup in `destroy()`**

The `thoughtContentEditor` component already has a `destroy()` method (around line 816). Find it:

```js
  destroy() {
    if (this._previewResizeHandler) {
      window.removeEventListener('resize', this._previewResizeHandler);
      this._previewResizeHandler = null;
    }
  },
```

Replace with:

```js
  destroy() {
    if (this._previewResizeHandler) {
      window.removeEventListener('resize', this._previewResizeHandler);
      this._previewResizeHandler = null;
    }
    if (this.focusOverlayOpen) {
      document.body.style.overflow = '';
    }
  },
```

- [ ] **Step 7: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: add focus mode state and methods to thoughtContentEditor"
```

---

### Task 4: Add focus mode markup to the Blade partial

**Files:**
- Modify: `resources/views/idea/partials/editable_thought_content.blade.php`

- [ ] **Step 1: Update the detailMarkdownRead editing branch (lines 47-58)**

Find the current editing div (after Task 2 changes):

```html
        <div
            class="mb-2"
            x-show="editing"
            x-on:keydown.escape.stop.prevent="cancelEdit()"
        >
            <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden"></textarea>
            <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
            <div class="flex items-center gap-2 mt-2">
                <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
            </div>
        </div>
```

Replace with:

```html
        <div
            x-show="editing"
            x-on:keydown.escape.stop.prevent="handleEditEscape()"
            :class="focusOverlayOpen ? 'fixed inset-0 z-50 flex flex-col p-6' : 'mb-2'"
        >
            <div
                x-show="focusOverlayOpen"
                x-cloak
                @click="toggleFocus()"
                class="absolute inset-0 bg-white -z-10"
                aria-hidden="true"
            ></div>
            <div :class="focusOverlayOpen ? 'max-w-4xl w-full mx-auto flex flex-col flex-1 min-h-0' : ''">
                <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden" :class="focusOverlayOpen ? 'flex-1 min-h-0' : ''"></textarea>
                <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                <div class="flex items-center gap-2 mt-2">
                    <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                    <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
                    <button type="button" x-show="!focusOverlayOpen" @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Focus</button>
                    <button type="button" x-show="focusOverlayOpen" x-cloak @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Close</button>
                </div>
            </div>
        </div>
```

- [ ] **Step 2: Update the non-detail editing branch (lines 110-119)**

Find the current editing template (after Task 2 changes):

```html
        <template x-if="editing">
            <div class="mb-2" x-on:keydown.escape.stop.prevent="cancelEdit()">
                <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden"></textarea>
                <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                <div class="flex items-center gap-2 mt-2">
                    <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                    <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
                </div>
            </div>
        </template>
```

Replace with:

```html
        <template x-if="editing">
            <div x-on:keydown.escape.stop.prevent="handleEditEscape()" :class="focusOverlayOpen ? 'fixed inset-0 z-50 flex flex-col p-6' : 'mb-2'">
                <div
                    x-show="focusOverlayOpen"
                    x-cloak
                    @click="toggleFocus()"
                    class="absolute inset-0 bg-white -z-10"
                    aria-hidden="true"
                ></div>
                <div :class="focusOverlayOpen ? 'max-w-4xl w-full mx-auto flex flex-col flex-1 min-h-0' : ''">
                    <textarea x-ref="editTextarea" x-model="draftContent" rows="4" @input="resizeTextarea()" class="{{ $editorClass }} resize-none overflow-hidden" :class="focusOverlayOpen ? 'flex-1 min-h-0' : ''"></textarea>
                    <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
                    <div class="flex items-center gap-2 mt-2">
                        <button type="button" @click="saveEdit()" :disabled="saveDisabled" class="text-[11px] font-medium text-white px-2 py-1 rounded bg-memory-violet disabled:opacity-50">Save</button>
                        <button type="button" @click="cancelEdit()" :disabled="saving" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo">Cancel</button>
                        <button type="button" x-show="!focusOverlayOpen" @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Focus</button>
                        <button type="button" x-show="focusOverlayOpen" x-cloak @click="toggleFocus()" class="text-[11px] font-medium text-slate-brand hover:text-deep-indigo ml-auto">Close</button>
                    </div>
                </div>
            </div>
        </template>
```

- [ ] **Step 3: Verify focus mode in the browser**

1. **Card editing (home page):** Edit a thought. Click "Focus". The editing area should go full-viewport with white background. Type content. Click "Close" or press Escape to exit focus mode. Press Escape again to cancel editing.
2. **Detail page editing:** Same test on the detail page.
3. **Save while focused:** Enter focus mode, edit content, click "Save". Should save, close focus, and return to read mode.
4. **Cancel while focused:** Enter focus mode, click "Cancel". Should close focus and revert content.
5. **Body scroll lock:** With focus open, try scrolling the page. It should not scroll. After closing, scrolling should work again.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/editable_thought_content.blade.php
git commit -m "feat: add focus mode overlay markup to editable thought content partial"
```

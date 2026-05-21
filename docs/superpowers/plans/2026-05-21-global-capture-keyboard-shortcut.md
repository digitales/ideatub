# Global Capture Keyboard Shortcut Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let authenticated users open a full-featured capture modal from any `layouts.idea` page via **⌘/** (Ctrl+/), while home keeps focusing the inline capture box.

**Architecture:** Extract the home capture UI into a shared Blade partial; mount it inline on `idea.index` and inside a layout-level modal elsewhere. Extend `captureBox()` with `data-placement="global"` to skip post-save redirects and notify the layout to close. Extend `ideaShortcuts` to route **⌘/** to `focus-capture` on home vs `ideatub-open-capture` elsewhere.

**Tech Stack:** Laravel 12, Blade, Alpine.js (`resources/js/app.js`), Pest feature tests.

**Spec:** [`docs/superpowers/specs/2026-05-21-global-capture-keyboard-shortcut-design.md`](../specs/2026-05-21-global-capture-keyboard-shortcut-design.md)

---

## File structure

| File | Responsibility |
|------|----------------|
| `resources/views/idea/partials/capture_box.blade.php` | **Create** — shared capture markup (drafts, form, video, import) |
| `resources/views/idea/index.blade.php` | **Modify** — replace inline capture block with `@include` |
| `resources/views/layouts/idea.blade.php` | **Modify** — global modal shell, `captureOpen`, events, Escape, palette/help copy |
| `resources/js/app.js` | **Modify** — `captureBox` placement + `ideaShortcuts` routing |
| `resources/views/help.blade.php` | **Modify** — shortcut table row |
| `tests/Feature/GlobalCaptureShortcutTest.php` | **Create** — HTML presence on non-home idea pages |

---

## Task 1: Feature test (global capture shell on Stream)

**Files:**
- Create: `tests/Feature/GlobalCaptureShortcutTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalCaptureShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_page_includes_global_capture_shell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-placement="global"', false);
        $response->assertSee('ideatub-global-capture', false);
    }

    public function test_home_page_does_not_include_global_capture_shell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertDontSee('ideatub-global-capture', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/GlobalCaptureShortcutTest.php -v`

Expected: FAIL — `data-placement="global"` / `ideatub-global-capture` not found on Stream.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/GlobalCaptureShortcutTest.php
git commit -m "test: assert global capture shell on idea layout pages"
```

---

## Task 2: Extract capture partial

**Files:**
- Create: `resources/views/idea/partials/capture_box.blade.php`
- Modify: `resources/views/idea/index.blade.php`

- [ ] **Step 1: Create the partial**

Create `resources/views/idea/partials/capture_box.blade.php` by moving lines 45–330 from `idea/index.blade.php` (the outer `x-data="captureBox()"` div through its closing `</div>` before `{{-- Thoughts list --}}`).

Add at the top of the partial (docblock-style comment optional):

```blade
{{--
  @param string $placement 'inline' | 'global'
  @param string $initialContent
  @param bool $forceHomeVideoMode
  @param bool $importUploadsEnabled
  @param \App\Models\Thought|null $replyingTo
  @param string|null $replyingToPreview
--}}
@php
    $placement = $placement ?? 'inline';
    $initialContent = $initialContent ?? '';
    $forceHomeVideoMode = $forceHomeVideoMode ?? false;
    $importUploadsEnabled = $importUploadsEnabled ?? false;
@endphp
```

On the root `<div>`:

- Add `data-placement="{{ $placement }}"`
- Keep `x-data="captureBox()"`, `@ideatub-load-draft.window`, all existing `data-*` attrs
- For **inline only**: keep `@focus-capture.window="focusCapture()"`, `class="ideatub-surface mb-3 p-4 …"`, focus-shell click handlers
- For **global**: use `class="p-0"` (modal panel provides chrome); omit `@focus-capture.window` (home-only)
- Change textarea `id="content"` to `id="{{ $placement === 'global' ? 'global-capture-content' : 'content' }}"` to avoid duplicate IDs if both ever rendered
- Hint line: if `$placement === 'inline'`, keep `⌘ + Enter to store · ⌘/ to focus`; if `global`, use `⌘ + Enter to store · Escape to close`
- Reply block and `data-focus-reply`: only when `$placement === 'inline'` and `$replyingTo` set (wrap `@if ($placement === 'inline' && isset($replyingTo) && $replyingTo)`)
- Import toolbar `@if`: `$importUploadsEnabled && $placement === 'inline' && ! isset($replyingTo)` — for global, use `$importUploadsEnabled && ! isset($replyingTo)` (no reply on global)

- [ ] **Step 2: Replace capture block in index**

In `resources/views/idea/index.blade.php`, keep the `@php` block that sets `$initialContent`, `$forceHomeVideoMode`, `$importUploadsEnabled`, then replace the capture `<div>…</div>` with:

```blade
    @include('idea.partials.capture_box', [
        'placement' => 'inline',
        'initialContent' => $initialContent,
        'forceHomeVideoMode' => $forceHomeVideoMode,
        'importUploadsEnabled' => $importUploadsEnabled,
        'replyingTo' => $replyingTo ?? null,
        'replyingToPreview' => $replyingToPreview ?? null,
    ])
```

Ensure `$replyingToPreview` is defined on the index view (use existing variable from controller).

- [ ] **Step 3: Smoke-check home in browser**

Open `/` logged in — capture box unchanged visually; **⌘/** still focuses textarea.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/capture_box.blade.php resources/views/idea/index.blade.php
git commit -m "refactor: extract capture box into shared partial"
```

---

## Task 3: Global capture shell in idea layout

**Files:**
- Modify: `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Add layout data for home detection**

On the existing `ideaShortcuts` wrapper `<div>`, add:

```blade
data-is-home-page="{{ request()->routeIs('idea.index') ? '1' : '0' }}"
```

- [ ] **Step 2: Add global modal markup (not on home)**

After the shortcuts palette `</div>` (before closing the `ideaShortcuts` wrapper), add:

```blade
        @unless (request()->routeIs('idea.index'))
            @php
                $globalInitialContent = '';
                $globalForceVideoMode = false;
                $globalImportUploadsEnabled = (bool) config('features.file_upload', false)
                    && \Illuminate\Support\Facades\Route::has('imports.quick')
                    && ! app(\App\Services\DemoMode::class)->enabled();
            @endphp
            <div
                id="ideatub-global-capture"
                x-show="captureOpen"
                x-cloak
                x-transition.opacity
                @ideatub-open-capture.window="openGlobalCapture()"
                @ideatub-capture-saved.window="closeGlobalCaptureAfterSave()"
                @keydown.escape.window="handleGlobalCaptureEscape()"
                @click.self="closeGlobalCapture()"
                class="ideatub-modal-backdrop"
                role="dialog"
                aria-modal="true"
                aria-labelledby="global-capture-title"
            >
                <div class="ideatub-modal-panel max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden" @click.stop>
                    <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3 border-b border-memory-violet/10 shrink-0">
                        <h2 id="global-capture-title" class="text-lg font-semibold text-deep-indigo">Capture thought</h2>
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-brand hover:text-deep-indigo"
                            @click="closeGlobalCapture()"
                        >Close</button>
                    </div>
                    <div class="overflow-y-auto px-5 py-4 min-h-0 flex-1" x-ref="globalCaptureMount">
                        @include('idea.partials.capture_box', [
                            'placement' => 'global',
                            'initialContent' => $globalInitialContent,
                            'forceHomeVideoMode' => $globalForceVideoMode,
                            'importUploadsEnabled' => $globalImportUploadsEnabled,
                            'replyingTo' => null,
                            'replyingToPreview' => null,
                        ])
                    </div>
                </div>
            </div>
        @endunless
```

- [ ] **Step 3: Run feature test (still failing on JS methods — markup only)**

Run: `php artisan test tests/Feature/GlobalCaptureShortcutTest.php -v`

Expected: `test_stream_page_includes_global_capture_shell` **PASS**; home test **PASS**.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/idea.blade.php
git commit -m "feat: add global capture modal shell to idea layout"
```

---

## Task 4: `ideaShortcuts` — open/close and ⌘/ routing

**Files:**
- Modify: `resources/js/app.js` (`ideaShortcuts` component, ~line 632)

- [ ] **Step 1: Extend state and init**

In `Alpine.data('ideaShortcuts', () => ({` add:

```javascript
  captureOpen: false,
  isHomePage: false,
```

In `init()`:

```javascript
    if (el?.dataset?.isHomePage !== undefined) {
      this.isHomePage = el.dataset.isHomePage === '1';
    }
```

Add methods:

```javascript
  openGlobalCapture() {
    if (this.shortcutsOpen) this.shortcutsOpen = false;
    this.captureOpen = true;
    this.$nextTick(() => {
      const textarea = document.getElementById('global-capture-content');
      textarea?.focus();
    });
  },

  closeGlobalCapture() {
    this.captureOpen = false;
  },

  closeGlobalCaptureAfterSave() {
    setTimeout(() => {
      this.captureOpen = false;
    }, 1500);
  },

  handleGlobalCaptureEscape() {
    if (!this.captureOpen) return;
    const mount = this.$refs.globalCaptureMount;
    const root = mount?.querySelector('[data-placement="global"]');
    const data = root && typeof Alpine !== 'undefined' ? Alpine.$data(root) : null;
    if (data?.focusOverlayOpen) {
      data.closeFocusOverlay();
      return;
    }
    this.closeGlobalCapture();
  },

  globalCaptureFocusOverlayOpen() {
    const mount = this.$refs.globalCaptureMount;
    const root = mount?.querySelector('[data-placement="global"]');
    const data = root && typeof Alpine !== 'undefined' ? Alpine.$data(root) : null;
    return !!data?.focusOverlayOpen;
  },
```

- [ ] **Step 2: Update `handleKey` for Escape and ⌘/**

Replace the Escape block start with:

```javascript
    if (e.key === 'Escape') {
      if (this.shortcutsOpen) this.shortcutsOpen = false;
      else if (this.searching) this.searching = false;
      else if (this.captureOpen) this.handleGlobalCaptureEscape();
      else if (window.location.search.includes('parent_id') && this.ideaIndexUrl)
        window.location = this.ideaIndexUrl;
      e.preventDefault();
      return;
    }
```

Replace the **⌘/** block:

```javascript
    if ((e.metaKey || e.ctrlKey) && e.key === '/') {
      if (this.shortcutsOpen) this.shortcutsOpen = false;
      if (this.isHomePage) {
        this.$dispatch('focus-capture');
      } else {
        this.openGlobalCapture();
      }
      e.preventDefault();
      return;
    }
```

- [ ] **Step 3: Manual test on Stream**

Logged in, visit `/stream`, press **⌘/** — modal opens, textarea focused. Escape closes. Inner Focus mode: Escape closes focus first, second Escape closes modal.

- [ ] **Step 4: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: route Cmd+/ to global capture modal off home"
```

---

## Task 5: `captureBox` placement — stay on page after global save

**Files:**
- Modify: `resources/js/app.js` (`captureBox`, ~line 70)

- [ ] **Step 1: Read placement in `init()`**

After `this._rootEl = this.$el;` add:

```javascript
    this.placement = this._rootEl?.dataset?.placement === 'global' ? 'global' : 'inline';
```

- [ ] **Step 2: Branch `submitCapture` success path**

After successful save (after draft delete, `this.content = ''`, message set, `fetchDrafts()`), replace the navigation block:

```javascript
      if (data.thought) {
        if (this.placement === 'global') {
          this.$dispatch('ideatub-capture-saved');
          setTimeout(() => { this.message = ''; }, 4000);
          return;
        }
        if (data.thought.parent_id) {
          this.appendCommentToParent(data.thought);
        } else {
          window.location = (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
        }
      } else {
        if (this.placement === 'global') {
          this.$dispatch('ideatub-capture-saved');
          setTimeout(() => { this.message = ''; }, 4000);
          return;
        }
        window.location = (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
      }
```

(Keep existing error handling and `finally { this.saving = false; }` unchanged.)

- [ ] **Step 3: Branch `submitVideoCapture` success path**

Replace:

```javascript
      const target = data.redirect || (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
      window.location = target;
```

with:

```javascript
      if (this.placement === 'global') {
        this.$dispatch('ideatub-capture-saved');
        setTimeout(() => { this.message = ''; }, 4000);
        return;
      }
      const target = data.redirect || (this._rootEl && this._rootEl.dataset.ideaIndexUrl) || window.location.pathname;
      window.location = target;
```

- [ ] **Step 4: Manual test**

On Stream: open modal, type short thought, **⌘+Enter** — stays on Stream, modal closes after ~1.5s. On home: save still redirects as before.

- [ ] **Step 5: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: global capture stays on page after save"
```

---

## Task 6: Help and shortcut palette copy

**Files:**
- Modify: `resources/views/layouts/idea.blade.php` (palette table row)
- Modify: `resources/views/help.blade.php`

- [ ] **Step 1: Update palette row**

Replace the “Focus capture” row with:

```blade
                        <tr>
                            <td class="py-1.5">Quick capture</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">⌘/ or Ctrl+/</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pl-4 text-slate-brand/80" colspan="2">Home: focus capture · Elsewhere: open capture modal</td>
                        </tr>
```

- [ ] **Step 2: Update Help table**

In `help.blade.php`, replace the “Focus capture” row similarly (one row for binding, one sub-row or second line for home vs elsewhere).

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/idea.blade.php resources/views/help.blade.php
git commit -m "docs: update shortcut help for global capture"
```

---

## Task 7: Final verification

- [ ] **Step 1: Run feature test**

Run: `php artisan test tests/Feature/GlobalCaptureShortcutTest.php -v`

Expected: PASS (both tests).

- [ ] **Step 2: Run related regression (optional but recommended)**

Run: `php artisan test tests/Feature/VideoStreamDisplayTest.php -v --filter=test_stream`

- [ ] **Step 3: Manual checklist**

| Check | Expected |
|-------|----------|
| Home **⌘/** | Focuses inline capture; no `#ideatub-global-capture` in DOM |
| Stream **⌘/** | Opens modal |
| Stream save text | Stays on Stream |
| Stream video URL | Video mode works; no redirect after save |
| **?** palette | Updated copy |
| `/help` | Updated copy |

- [ ] **Step 4: Commit spec/plan if not committed**

```bash
git add docs/superpowers/specs/2026-05-21-global-capture-keyboard-shortcut-design.md docs/superpowers/plans/2026-05-21-global-capture-keyboard-shortcut.md
git commit -m "docs: global capture keyboard shortcut spec and plan"
```

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Full capture in modal | Task 2–3 |
| Top-level only | Task 2 (no reply on global) |
| Stay on page after global save | Task 5 |
| Home inline unchanged | Task 2, 4 |
| **⌘/** binding | Task 4 |
| `layouts.idea` only | Task 3 (`@unless` home) |
| No global shell on home | Task 1, 3 |
| Escape ordering | Task 4 |
| Help/palette copy | Task 6 |
| Feature test | Task 1, 7 |

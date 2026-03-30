# Card Preview Expand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a consistent collapsed preview for long main thought text on the signed-in idea home and stream, with inline `Read more` / `Show less` expansion that resets on reload.

**Architecture:** Keep the current Blade list cards and shared `editable_thought_content` partial as the source of truth for read/edit rendering. Opt the list surfaces into a preview mode, then extend the existing `thoughtContentEditor` Alpine component to measure overflow, clamp to a 15-line max height in read mode, expose an accessible sibling toggle button, and re-measure on mount and resize so dynamically inserted cards behave the same as the initial page load. To make the short-thought/no-toggle rule testable without adding a full browser harness, extract the overflow decision into a small JS helper with unit coverage and call that helper from `app.js`.

**Tech Stack:** Laravel 12, Blade, Alpine.js, Tailwind CSS 4, Vite, Laravel feature tests, manual browser verification.

**Spec:** `docs/superpowers/specs/2026-03-30-card-preview-expand-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `package.json` | Add a lightweight JS test command for preview-helper coverage |
| `resources/js/lib/thoughtPreview.js` | Hold the small pure helper(s) for collapsed max-height and “should show toggle” decisions |
| `resources/js/lib/thoughtPreview.test.js` | Cover short-thought/no-toggle and long-thought/toggle cases without relying on browser E2E infrastructure |
| `resources/views/idea/partials/editable_thought_content.blade.php` | Centralize preview-mode markup, content-region identifiers, sibling expand button, and edit-mode bypass without affecting the thought detail page |
| `resources/views/idea/index_thought_cards.blade.php` | Opt the signed-in idea home cards into preview mode |
| `resources/views/idea/stream_thoughts.blade.php` | Opt the stream cards into the same preview mode, including typed stream variants that reuse this partial |
| `resources/js/app.js` | Extend `thoughtContentEditor()` with preview state, overflow measurement, resize re-measurement, button labels, and edit-mode interactions |
| `tests/Feature/IdeaPageTest.php` | Cover signed-in home preview hooks, accessible toggle markup, and preserved detail-link behavior |
| `tests/Feature/StreamPageTest.php` | Cover stream preview hooks, accessible toggle markup, and preserved comment/wrapping behavior |

---

## Task 1: Add failing feature coverage for preview-mode markup

**Files:**
- Modify: `tests/Feature/IdeaPageTest.php`
- Modify: `tests/Feature/StreamPageTest.php`
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add a failing signed-in home test for preview-mode hooks**

Add a test like:

```php
public function test_idea_page_long_thought_renders_preview_mode_hooks(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => str_repeat("Long preview line for testing.\n", 25),
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertOk();
    $response->assertSee('data-thought-preview-region="thought-preview-'.$thought->id.'"', false);
    $response->assertSee('data-thought-preview-toggle="thought-preview-'.$thought->id.'"', false);
    $response->assertSee('aria-controls="thought-preview-'.$thought->id.'"', false);
}
```

Reuse the existing `xpathFromResponse()` helper already present in `IdeaPageTest` when you need DOM-structure assertions.

- [ ] **Step 2: Add a failing signed-in home test that the main thought still links to detail**

Keep the existing detail-link assertion, but add a structural assertion that the toggle button is not nested inside the anchor. A DOM/XPath assertion is fine:

```php
$xpath = $this->xpathFromResponse($response);
$buttonInsideLink = $xpath->query("//a[@href='".route('thoughts.show', $thought)."']//button");
$this->assertSame(0, $buttonInsideLink->length);
```

- [ ] **Step 3: Add a failing stream test for preview-mode hooks**

Add a stream-side counterpart:

```php
public function test_stream_page_long_thought_renders_preview_mode_hooks(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => str_repeat("Long stream preview line for testing.\n", 25),
    ]);

    $response = $this->actingAs($user)->get(route('idea.stream'));

    $response->assertOk();
    $response->assertSee('data-thought-preview-region="thought-preview-'.$thought->id.'"', false);
    $response->assertSee('data-thought-preview-toggle="thought-preview-'.$thought->id.'"', false);
    $response->assertSee('aria-controls="thought-preview-'.$thought->id.'"', false);
}
```

- [ ] **Step 4: Add a failing stream test that comments remain separate from the main-thought preview markup**

Create a root thought plus one comment and assert the existing comment preview remains rendered while the main thought gets the preview-region hook:

```php
$response->assertSee('Comment body that should still appear in the stream preview');
$response->assertSee('data-thought-preview-region="thought-preview-'.$thought->id.'"', false);
```

Do not assert `data-comments-list` on the stream view unless you deliberately add that attribute as a no-behavior-change consistency tweak. The goal is to prove comment rendering stays intact, not to force unrelated markup changes.

- [ ] **Step 5: Add a failing stream/home test for accessibility attributes**

Assert the rendered toggle exposes both `aria-expanded` and `aria-controls`:

```php
$response->assertSee(':aria-expanded="isPreviewExpanded.toString()"', false);
$response->assertSee('aria-controls="thought-preview-'.$thought->id.'"', false);
```

- [ ] **Step 6: Add failing wrapping and stream-side structure checks**

Mirror the existing safe-wrap style checks so preview mode does not drop the current overflow protections:

```php
$html = $response->getContent();
$this->assertStringContainsString('break-words [overflow-wrap:anywhere]', $html);
```

Also add the same “button not nested inside the detail link” XPath assertion to `StreamPageTest`, not just `IdeaPageTest`.

- [ ] **Step 7: Run the focused idea/stream tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v
```

Expected: FAIL because the current list markup does not render any preview-region IDs, toggle button, or preview-specific accessibility hooks.

- [ ] **Step 8: Leave the failing tests uncommitted until Tasks 2 and 3 are green**

Do not create a failing-tests-only commit in this plan. Keep the new assertions local until the implementation passes.

---

## Task 2: Add preview-mode Blade markup in the shared content partial

**Files:**
- Modify: `resources/views/idea/partials/editable_thought_content.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add preview-mode inputs to the shared partial**

At the top of `editable_thought_content.blade.php`, add opt-in config values like:

```php
@php
    $previewLines = $previewLines ?? null;
    $previewRegionId = $previewRegionId ?? ('thought-preview-' . $thought->id);
@endphp
```

Keep the default `null` so detail pages and any other callers remain unchanged.

- [ ] **Step 2: Pass preview mode from the signed-in home cards**

Update the include in `resources/views/idea/index_thought_cards.blade.php` to pass:

```php
'previewLines' => 15,
'previewRegionId' => 'thought-preview-' . $thought->id,
```

Do not change comment-preview rendering.

- [ ] **Step 3: Pass preview mode from the stream cards**

Update the include in `resources/views/idea/stream_thoughts.blade.php` to pass the same preview configuration:

```php
'previewLines' => 15,
'previewRegionId' => 'thought-preview-' . $thought->id,
```

This automatically covers typed stream variants because they reuse this partial.

Do not pass `previewLines` from the public marketing homepage or any section/comment preview callers. Section previews stay unchanged.

- [ ] **Step 4: Replace the current read-only markup with a preview-capable structure**

Inside `editable_thought_content.blade.php`, keep the existing link wrapper for the paragraph, but render the button as a sibling beneath it:

```blade
<div
    x-data="thoughtContentEditor({
        content: @js($thought->content),
        updateUrl: @js(route('ideas.update-content', $thought)),
        editable: @js($editable),
        previewLines: @js($previewLines),
        previewRegionId: @js($previewRegionId),
    })"
>
    <template x-if="!editing">
        <div class="mb-2">
            @if ($viewHref)
                <a href="{{ $viewHref }}" class="{{ $viewLinkClass }}">
                    <p
                        :id="previewRegionId"
                        x-ref="previewRegion"
                        data-thought-preview-region="{{ $previewRegionId }}"
                        class="{{ $displayClass }}"
                        :class="previewTextClass"
                        :style="previewTextStyle"
                        x-text="viewContent"
                    ></p>
                </a>
            @else
                <p
                    :id="previewRegionId"
                    x-ref="previewRegion"
                    data-thought-preview-region="{{ $previewRegionId }}"
                    class="{{ $displayClass }}"
                    :class="previewTextClass"
                    :style="previewTextStyle"
                    x-text="viewContent"
                ></p>
            @endif

            <button
                x-cloak
                x-show="showPreviewToggle"
                type="button"
                data-thought-preview-toggle="{{ $previewRegionId }}"
                class="mt-1 text-[11px] font-medium text-memory-violet hover:text-deep-indigo"
                :aria-expanded="isPreviewExpanded.toString()"
                :aria-controls="previewRegionId"
                @click.stop.prevent="togglePreview()"
                x-text="isPreviewExpanded ? 'Show less' : 'Read more'"
            ></button>
        </div>
    </template>
```

- [ ] **Step 5: Keep edit mode isolated from preview markup**

Do not change the textarea path except to ensure preview-only markup is part of the read view. The editing template should continue to render the full textarea with the existing `x-on:keydown.escape.stop.prevent="cancelEdit()"` hook.

- [ ] **Step 6: Run the focused feature tests and verify the new markup assertions pass while JS behavior is still incomplete**

Run:

```bash
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v
```

Expected after this Blade-only step:

- preview-region IDs
- preview-toggle `data-*` hooks
- `aria-controls`
- sibling button/link structure

should pass, while runtime overflow behavior still depends on Task 3.

- [ ] **Step 7: Commit the Blade preview markup once Task 3 is green**

Run later, after the JS behavior and tests pass:

```bash
git add resources/views/idea/partials/editable_thought_content.blade.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
git commit -m "feat: add thought card preview markup"
```

---

## Task 3: Extend the Alpine thought editor with preview measurement and toggle state

**Files:**
- Modify: `package.json`
- Create: `resources/js/lib/thoughtPreview.js`
- Create: `resources/js/lib/thoughtPreview.test.js`
- Modify: `resources/js/app.js`
- Test: manual verification on `idea.index` and `idea.stream`
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add a lightweight JS test harness for preview rules**

Install Vitest and add a script:

```bash
npm install -D vitest
```

Update `package.json`:

```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "test:js": "vitest run"
  }
}
```

- [ ] **Step 2: Add failing JS unit tests for short and long preview cases**

Create `resources/js/lib/thoughtPreview.test.js`:

```js
import { describe, expect, it } from 'vitest';
import { getThoughtPreviewMetrics } from './thoughtPreview';

describe('getThoughtPreviewMetrics', () => {
  it('does not show the toggle when full height fits within the preview lines', () => {
    expect(
      getThoughtPreviewMetrics({ fullHeight: 240, lineHeight: 16, previewLines: 15 })
    ).toEqual({
      collapsedMaxHeight: 240,
      showPreviewToggle: false,
    });
  });

  it('shows the toggle when full height exceeds the preview lines', () => {
    expect(
      getThoughtPreviewMetrics({ fullHeight: 320, lineHeight: 16, previewLines: 15 })
    ).toEqual({
      collapsedMaxHeight: 240,
      showPreviewToggle: true,
    });
  });
});
```

- [ ] **Step 3: Run the JS test file and verify it fails**

Run:

```bash
npm run test:js -- resources/js/lib/thoughtPreview.test.js
```

Expected: FAIL because the helper file does not exist yet.

- [ ] **Step 4: Implement the pure preview helper**

Create `resources/js/lib/thoughtPreview.js`:

```js
export function getThoughtPreviewMetrics({ fullHeight, lineHeight, previewLines }) {
  if (
    !Number.isFinite(fullHeight) ||
    !Number.isFinite(lineHeight) ||
    !Number.isFinite(previewLines) ||
    previewLines <= 0
  ) {
    return { collapsedMaxHeight: null, showPreviewToggle: false };
  }

  const collapsedMaxHeight = lineHeight * previewLines;

  return {
    collapsedMaxHeight,
    showPreviewToggle: fullHeight > collapsedMaxHeight + 1,
  };
}
```

- [ ] **Step 5: Re-run the JS unit tests and verify they pass**

Run:

```bash
npm run test:js -- resources/js/lib/thoughtPreview.test.js
```

Expected: PASS, including the short-thought/no-toggle case required by the spec.

- [ ] **Step 6: Extend `thoughtContentEditor()` inputs and state**

Change the Alpine signature from:

```js
Alpine.data('thoughtContentEditor', ({ content, updateUrl, editable = false, previewMaxLength = null }) => ({
```

to something like:

```js
Alpine.data(
  'thoughtContentEditor',
  ({ content, updateUrl, editable = false, previewMaxLength = null, previewLines = null, previewRegionId = null }) => ({
    content: content || '',
    originalContent: content || '',
    draftContent: content || '',
    updateUrl: updateUrl || '',
    editable: !!editable,
    previewMaxLength: previewMaxLength == null || previewMaxLength === '' ? null : Number(previewMaxLength),
    previewLines: previewLines == null || previewLines === '' ? null : Number(previewLines),
    previewRegionId: previewRegionId || '',
    editing: false,
    saving: false,
    error: '',
    isPreviewExpanded: false,
    showPreviewToggle: false,
    collapsedMaxHeight: null,
```

- [ ] **Step 7: Import and use the preview helper in `resources/js/app.js`**

At the top of `resources/js/app.js`, add:

```js
import { getThoughtPreviewMetrics } from './lib/thoughtPreview';
```

Use that helper inside `measurePreviewOverflow()` so runtime behavior and automated JS coverage share the same rule.

- [ ] **Step 8: Add init/destroy hooks for preview measurement**

Add:

```js
init() {
  this.$nextTick(() => this.measurePreviewOverflow());
  this._previewResizeHandler = () => this.measurePreviewOverflow();
  window.addEventListener('resize', this._previewResizeHandler);
},

destroy() {
  if (this._previewResizeHandler) {
    window.removeEventListener('resize', this._previewResizeHandler);
  }
},
```

This keeps new cards consistent after Alpine initializes and re-measures on resize.

If manual verification later shows container-only width changes that do not trigger `window.resize`, add a small `ResizeObserver` on `this.$refs.previewRegion` as allowed by the spec instead of inventing a larger abstraction.

- [ ] **Step 9: Add a concrete overflow-measurement flow in Alpine**

Implement a small helper using the rendered paragraph’s computed line height:

```js
measurePreviewOverflow() {
  if (!this.previewEnabled || this.editing) {
    this.showPreviewToggle = false;
    this.collapsedMaxHeight = null;
    return;
  }

  const el = this.$refs.previewRegion;
  if (!el) return;

  const lineHeight = Number.parseFloat(window.getComputedStyle(el).lineHeight || '0');
  if (!lineHeight || !Number.isFinite(lineHeight)) return;

  const previousMaxHeight = el.style.maxHeight;
  el.style.maxHeight = 'none';
  const fullHeight = el.scrollHeight;
  el.style.maxHeight = previousMaxHeight;

  const metrics = getThoughtPreviewMetrics({
    fullHeight,
    lineHeight,
    previewLines: this.previewLines,
  });

  this.collapsedMaxHeight = metrics.collapsedMaxHeight;
  this.showPreviewToggle = metrics.showPreviewToggle;
  if (!this.showPreviewToggle) this.isPreviewExpanded = false;
},
```

If the first mount measurement is flaky because layout has not settled yet, retry once with `requestAnimationFrame(() => this.measurePreviewOverflow())`.

- [ ] **Step 10: Add small computed helpers for preview styling**

Add helpers like:

```js
get previewEnabled() {
  return this.previewLines != null && !Number.isNaN(this.previewLines) && this.previewLines > 0;
},

get previewTextClass() {
  if (!this.previewEnabled || this.editing || this.isPreviewExpanded || !this.showPreviewToggle) return '';
  return 'overflow-hidden';
},

get previewTextStyle() {
  if (!this.previewEnabled || this.editing || this.isPreviewExpanded || !this.showPreviewToggle || this.collapsedMaxHeight == null) {
    return '';
  }
  return `max-height: ${this.collapsedMaxHeight}px;`;
},
```

If you add a fade treatment, keep it lightweight and respect reduced-motion preferences.

- [ ] **Step 11: Add the toggle action and edit-mode resets**

Implement:

```js
togglePreview() {
  if (!this.showPreviewToggle) return;
  this.isPreviewExpanded = !this.isPreviewExpanded;
},
```

Update `startEdit()` and `cancelEdit()` to re-measure after state changes:

```js
startEdit() {
  if (!this.editable) return;
  this.isPreviewExpanded = true;
  this.editing = true;
  this.draftContent = this.content;
  this.error = '';
  this.$nextTick(() => this.$el.querySelector('textarea')?.focus());
},

cancelEdit() {
  this.editing = false;
  this.draftContent = this.content;
  this.error = '';
  this.$nextTick(() => this.measurePreviewOverflow());
},
```

After successful save, also re-measure:

```js
this.editing = false;
this.$nextTick(() => this.measurePreviewOverflow());
```

- [ ] **Step 12: Build the frontend assets and fix any JS errors**

Run:

```bash
npm run build
```

Expected: PASS with no syntax or bundling errors in `resources/js/app.js`.

- [ ] **Step 13: Re-run the JS and PHP tests and verify they pass**

Run:

```bash
npm run test:js -- resources/js/lib/thoughtPreview.test.js
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v
```

Expected: PASS for the JS helper coverage, including the short-thought/no-toggle rule, plus all idea/stream feature coverage.

- [ ] **Step 14: Commit the Alpine behavior and tests**

Run:

```bash
git add package.json resources/js/lib/thoughtPreview.js resources/js/lib/thoughtPreview.test.js resources/js/app.js resources/views/idea/partials/editable_thought_content.blade.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
git commit -m "feat: add inline thought card expansion"
```

---

## Task 4: Verify runtime behavior and clean up edge cases

**Files:**
- Modify: any files touched in Tasks 2-3 if fixes are needed
- Test: `tests/Feature/IdeaPageTest.php`
- Test: `tests/Feature/StreamPageTest.php`
- Test: manual browser verification on `idea.index` and `idea.stream`

- [ ] **Step 1: Run the focused test suite one more time**

Run:

```bash
npm run test:js -- resources/js/lib/thoughtPreview.test.js
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php -v
```

Expected: PASS.

- [ ] **Step 2: Read diagnostics for the changed files and fix any introduced issues**

Check diagnostics for:

- `resources/js/lib/thoughtPreview.js`
- `resources/views/idea/partials/editable_thought_content.blade.php`
- `resources/views/idea/index_thought_cards.blade.php`
- `resources/views/idea/stream_thoughts.blade.php`
- `resources/js/app.js`

Fix any new problems before finalizing.

- [ ] **Step 3: Manually verify the signed-in idea home**

On `route('idea.index')`, verify:

- a long thought appears collapsed to roughly 15 lines
- `Read more` expands inline without navigation
- `Show less` collapses it again
- short thoughts do not show a toggle after Alpine initializes
- entering inline edit mode shows the full textarea and no broken clamp styling
- the toggle is keyboard reachable and activates with Enter/Space

- [ ] **Step 4: Manually verify the stream and dynamic-insert cases**

On `route('idea.stream')`, verify:

- long stream thoughts behave the same as the signed-in home
- comments remain unchanged
- resizing the viewport re-measures overflow correctly
- cards inserted by load-more or stream refresh initialize collapsed and still show the toggle when needed
- expanding a card and then triggering a stream/index AJAX refetch resets the refreshed card back to collapsed
- reload resets expanded cards back to collapsed

- [ ] **Step 5: Commit any final verification fixes**

Run:

```bash
git add package.json resources/js/lib/thoughtPreview.js resources/js/lib/thoughtPreview.test.js resources/js/app.js resources/views/idea/partials/editable_thought_content.blade.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
git commit -m "test: finalize thought card preview coverage"
```

If no verification fixes were needed after the previous commit, skip this commit.

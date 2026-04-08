# Thought detail content edit (`content_html`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add owner-only markdown editing on the thought detail page with server-rendered read view and JSON `content_html` on save (no full reload), including chunked document sections.

**Architecture:** Reuse `PATCH ideas.update-content` and extend its JSON body with `content_html` from the same CommonMark + `renderDemoSafeMarkdown()` pipeline as `show()`, keyed as `thought_content` vs `thought_content_section` via existing `isStructuredDocumentSection()`. Build an ordered `$thoughtDetailContentBlocks` array in `IdeaController::show` and render it with the shared `editable_thought_content` partial extended for HTML read mode. Extend Alpine `thoughtContentEditor` in `resources/js/app.js` to hold `contentHtml` and apply `data.content_html` after save.

**Tech stack:** Laravel 12, Blade, Alpine.js 3, CommonMark (`league/commonmark`), Pest/PHPUnit, Vitest (optional if touching only PHP-invisible JS behavior).

**Spec:** `docs/superpowers/specs/2026-04-08-thought-detail-content-edit-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/IdeaController.php` | Private helper `renderedThoughtBodyHtml(Thought $thought)`; refactor `show()` to use it for root + sections; build `$thoughtDetailContentBlocks`; extend `updateContent()` JSON. |
| `resources/views/idea/show.blade.php` | Pass blocks into Content article; loop blocks instead of inline `{!! contentHtml !!}` + section foreach. |
| `resources/views/idea/partials/editable_thought_content.blade.php` | New props: `detailMarkdownRead`, `contentHtml` (initial server HTML); third read branch: prose `div` with `x-html="contentHtml"`. |
| `resources/js/app.js` | Extend `thoughtContentEditor`: state `detailMarkdownRead`, `contentHtml`; `saveEdit` applies `data.content_html` when present and `detailMarkdownRead`. |
| `tests/Feature/UpdateThoughtContentTest.php` | Assert `content_html` shape on JSON success. |
| `tests/Feature/ThoughtShowPageTest.php` | Owner sees `Edit content`; non-owner and demo do not. |

---

### Task 1: Failing test — JSON includes `content_html`

**Files:**

- Modify: `tests/Feature/UpdateThoughtContentTest.php`

- [ ] **Step 1: Add test method**

Append:

```php
public function test_json_response_includes_content_html_after_update(): void
{
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'content' => 'Before',
        'embedding' => null,
    ]);

    $response = $this->actingAs($owner)->patchJson(route('ideas.update-content', $thought), [
        'content' => 'Hello **world**',
    ]);

    $response->assertOk();
    $response->assertJsonPath('content', 'Hello **world**');
    $html = $response->json('content_html');
    $this->assertIsString($html);
    $this->assertStringContainsString('<p', $html);
    $this->assertStringContainsString('world', $html);
}
```

- [ ] **Step 2: Run test — expect failure**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/UpdateThoughtContentTest.php::test_json_response_includes_content_html_after_update
```

Expected: FAIL — `content_html` missing or null (or assertion on `<p` fails).

- [ ] **Step 3: Commit test only (optional checkpoint)**

```bash
git add tests/Feature/UpdateThoughtContentTest.php
git commit -m "test: expect content_html on thought content JSON update"
```

---

### Task 2: `updateContent` returns `content_html`

**Files:**

- Modify: `app/Http/Controllers/IdeaController.php` (add private helper near other markdown helpers; change `updateContent` around lines 1066–1088)

- [ ] **Step 1: Add private helper**

Add a **private** method on `IdeaController` (place it next to `renderDemoSafeMarkdown` or `isStructuredDocumentSection` for discoverability):

```php
/**
 * HTML body for a thought's markdown, matching IdeaController::show rendering and demo obfuscation.
 */
private function renderedThoughtBodyHtml(Thought $thought): string
{
    $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
    $context = $this->isStructuredDocumentSection($thought)
        ? 'thought_content_section'
        : 'thought_content';

    return $this->renderDemoSafeMarkdown($converter, (string) $thought->content, $context);
}
```

Ensure `use League\CommonMark\CommonMarkConverter;` exists at the top of the file (it already does for `show`).

- [ ] **Step 2: Extend JSON response**

Replace the JSON return in `updateContent` (currently `return response()->json(['content' => $thought->content]);`) with:

```php
$thought->refresh();

return response()->json([
    'content' => $thought->content,
    'content_html' => $this->renderedThoughtBodyHtml($thought),
]);
```

Call **`$thought->refresh()`** after `update` so the model reflects any accessors/mutators before rendering.

- [ ] **Step 3: Run UpdateThoughtContentTest**

Run:

```bash
php artisan test tests/Feature/UpdateThoughtContentTest.php
```

Expected: PASS (all tests in file).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "feat: return content_html from thought content JSON update"
```

---

### Task 3: Refactor `show()` markdown to use helper and build `$thoughtDetailContentBlocks`

**Files:**

- Modify: `app/Http/Controllers/IdeaController.php` (`show` method ~182–272)

- [ ] **Step 1: Replace inline converter usage for non-email body**

Inside `if ($thought->source !== 'email')`, after creating `$contentHtml` / section chunks, refactor to:

1. Set `$contentHtml = $this->renderedThoughtBodyHtml($thought);`
2. Build ordered section **models**:

```php
$sectionThoughts = $thought->comments
    ->filter(fn (Thought $comment): bool => $this->isStructuredDocumentSection($comment))
    ->sortBy(fn (Thought $comment): int => (int) data_get($comment->source_metadata, 'section_index', PHP_INT_MAX))
    ->values();

$documentSectionHtmlChunks = $sectionThoughts
    ->map(fn (Thought $section): string => $this->renderedThoughtBodyHtml($section))
    ->all();
```

This preserves `ThoughtDetailPresenter::forShow(..., documentSectionHtmlChunks: $documentSectionHtmlChunks)` unchanged for any code still reading the presenter.

- [ ] **Step 2: Build `$thoughtDetailContentBlocks`**

Still inside `if ($thought->source !== 'email')`, after the above:

```php
$detailContentEditable = auth()->check()
    && (int) auth()->id() === (int) $thought->user_id
    && ! app(\App\Services\DemoMode::class)->enabled();

$thoughtDetailContentBlocks = [
    [
        'thought' => $thought,
        'content_html' => $contentHtml,
        'editable' => $detailContentEditable,
    ],
];

foreach ($sectionThoughts as $sectionThought) {
    $thoughtDetailContentBlocks[] = [
        'thought' => $sectionThought,
        'content_html' => $this->renderedThoughtBodyHtml($sectionThought),
        'editable' => $detailContentEditable,
    ];
}
```

Use `app(\App\Services\DemoMode::class)` (matches `show.blade.php`) so no extra `use` line is required.

When `$thought->source === 'email'`, set `$thoughtDetailContentBlocks = [];` before the view return (initialize variable in all branches).

- [ ] **Step 3: Pass blocks to the view**

Change the `return view('idea.show', ...)` to include the new variable:

```php
return view('idea.show', [
    'thoughtDetail' => $thoughtDetail,
    'thoughtDetailContentBlocks' => $thoughtDetailContentBlocks ?? [],
]);
```

Ensure `$thoughtDetailContentBlocks` is defined on every path (e.g. default `[]` for email).

- [ ] **Step 4: Run regression tests**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "refactor: build thought detail content blocks for editable markdown"
```

---

### Task 4: Blade — detail Content article + `editable_thought_content` detail mode

**Files:**

- Modify: `resources/views/idea/show.blade.php` (Content `article` ~25–40)
- Modify: `resources/views/idea/partials/editable_thought_content.blade.php`

- [ ] **Step 1: `show.blade.php` — non-email branch**

Replace the inner `@else` block that currently renders:

```blade
<div class="prose ...">
    {!! $thoughtDetail->contentHtml() !!}
    @foreach ($thoughtDetail->documentSectionHtmlChunks() as $sectionHtml)
        {!! $sectionHtml !!}
    @endforeach
</div>
```

with a loop over `$thoughtDetailContentBlocks`, wrapping each block in a container with spacing, e.g.:

```blade
@foreach ($thoughtDetailContentBlocks as $block)
    <div class="mb-8 last:mb-0">
        @include('idea.partials.editable_thought_content', [
            'thought' => $block['thought'],
            'editable' => $block['editable'],
            'displayContent' => '',
            'rawEditorContent' => $block['editable'] ? $block['thought']->content : '',
            'detailMarkdownRead' => true,
            'contentHtml' => $block['content_html'],
            'displayClass' => 'text-[14px] md:text-[15px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line break-words [overflow-wrap:anywhere]',
            'editorClass' => 'w-full text-[14px] md:text-[15px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20',
        ])
    </div>
@endforeach
```

Keep the outer `article` and the **Content** heading as they are. When `$thoughtDetailContentBlocks` is empty (should not happen for non-email markdown thoughts), the inner area can remain empty.

- [ ] **Step 2: `editable_thought_content.blade.php` — `@php` defaults**

Near the top, after existing `@php` assignments, add:

```php
$detailMarkdownRead = (bool) ($detailMarkdownRead ?? false);
$contentHtmlInitial = $contentHtml ?? '';
```

- [ ] **Step 3: Pass flags into Alpine `x-data`**

Extend the `thoughtContentEditor({ ... })` call with:

```blade
detailMarkdownRead: @js($detailMarkdownRead),
contentHtml: @js($contentHtmlInitial),
```

- [ ] **Step 4: Read template branch for detail HTML**

Inside `<template x-if="!editing">`, after the `@if ($previewMode)` block, add `@elseif ($detailMarkdownRead)`:

```blade
@elseif ($detailMarkdownRead)
    <div class="flex justify-end mb-2" x-show="editable">
        <button
            type="button"
            class="text-[12px] font-medium text-slate-brand hover:text-deep-indigo"
            @click="startEdit()"
            aria-label="Edit content"
        >Edit</button>
    </div>
    <div
        class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]"
        x-html="contentHtml"
    ></div>
```

When `editable` is false (demo / non-owner), hide the Edit row via `x-show="editable"` on the flex wrapper.

- [ ] **Step 5: Edit template**

The existing `<template x-if="editing">` block should apply unchanged for detail mode (textarea + Save/Cancel). Ensure `detailMarkdownRead` does not require `previewMode` measuring (it stays false).

- [ ] **Step 6: Commit Blade**

```bash
git add resources/views/idea/show.blade.php resources/views/idea/partials/editable_thought_content.blade.php
git commit -m "feat: editable markdown blocks on thought detail page"
```

---

### Task 5: Alpine — apply `content_html` after save

**Files:**

- Modify: `resources/js/app.js` (`thoughtContentEditor`, ~634–778)

- [ ] **Step 1: Extend factory parameters and state**

Change the signature to accept defaults:

```javascript
Alpine.data('thoughtContentEditor', ({
  content,
  displayContent,
  rawEditorContent,
  updateUrl,
  editable = false,
  previewMaxLength = null,
  previewMode = false,
  detailMarkdownRead = false,
  contentHtml: initialContentHtml = '',
}) => ({
```

Inside the returned object, add:

```javascript
  detailMarkdownRead: !!detailMarkdownRead,
  contentHtml: initialContentHtml || '',
```

- [ ] **Step 2: Update `saveEdit` success branch**

After `this.content = data.content ?? trimmed;` add:

```javascript
      if (this.detailMarkdownRead && typeof data.content_html === 'string') {
        this.contentHtml = data.content_html;
      }
```

- [ ] **Step 3: Run JS unit tests (if any break)**

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && npm run test:js
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: thoughtContentEditor applies content_html after save on detail read"
```

---

### Task 6: Feature tests — detail page Edit affordance

**Files:**

- Modify: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Owner sees Edit**

Add:

```php
public function test_thought_detail_shows_content_edit_for_owner(): void
{
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'content' => 'Unique detail edit body zed-4421',
        'source' => 'web',
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('aria-label="Edit content"', false);
}
```

- [ ] **Step 2: Non-owner**

No new test required: `test_other_user_cannot_view_thought_show_page` already asserts `403` for `thoughts.show` as another user, so the Edit control cannot appear.

- [ ] **Step 3: Demo mode hides Edit**

Mirror `test_demo_mode_thought_detail_does_not_expose_tag_edit_affordance`:

```php
public function test_demo_mode_thought_detail_does_not_expose_content_edit_affordance(): void
{
    config(['services.demo_mode.enabled' => true]);

    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'content' => 'Demo detail body zed-1199',
        'source' => 'web',
        'metadata' => ['tags' => ['alphademo']],
    ]);

    $response = $this->withSession([
        \App\Services\DemoMode::SEED_SESSION_KEY => 'seed-detail-content-edit-demo',
    ])->actingAs($owner)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertDontSee('aria-label="Edit content"', false);
}
```

Adjust imports (`DemoMode`) to match neighboring tests in the same file.

- [ ] **Step 4: Run ThoughtShowPageTest**

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php
```

Fix any assertion mismatches (e.g. if Edit is not present because `source` must be non-email — use `'source' => 'web'`).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ThoughtShowPageTest.php
git commit -m "test: thought detail content edit visibility for owner and demo"
```

---

### Task 7: Format and full test sweep

- [ ] **Step 1: Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 2: Targeted + smoke**

```bash
php artisan test tests/Feature/UpdateThoughtContentTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
```

Expected: PASS (card surfaces still render `editable_thought_content` without `detailMarkdownRead`).

- [ ] **Step 3: Final commit (if Pint changed files)**

```bash
git add -A && git commit -m "style: pint after thought detail content edit"
```

---

## Plan self-review

| Spec section | Task covering it |
|--------------|------------------|
| JSON `content_html` + same markdown pipeline | Task 2 |
| Root + structured sections on detail | Tasks 3–4 |
| Owner + demo rules | Task 3, Task 6 |
| Alpine detail read + save | Tasks 4–5 |
| Security (server-only HTML) | Task 2 |
| Tests | Tasks 1, 6, 7 |

**Placeholder scan:** None.

**Type/name consistency:** `content_html` in JSON and PHP; Alpine `contentHtml`; `detailMarkdownRead` flag aligned between Blade and JS.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-08-thought-detail-content-edit.md`.

**Two execution options:**

1. **Subagent-driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration. **Required sub-skill:** subagent-driven-development.

2. **Inline execution** — Run tasks in this session with checkpoints. **Required sub-skill:** executing-plans.

Which approach do you want?

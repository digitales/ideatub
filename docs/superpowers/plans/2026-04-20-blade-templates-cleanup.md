# Blade templates cleanup (research + shared research) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move comment defaults and per-section presenter output out of Blade into PHP (`ResearchCommentsPresenter` + a small view-data type), migrate `research_content`, `shared_research/readonly`, and `comments/_thread` per spec [`docs/superpowers/specs/2026-04-20-blade-templates-design.md`](../../specs/2026-04-20-blade-templates-design.md), without changing product behavior.

**Architecture:** Extend `App\View\Presenters\Comments\ResearchCommentsPresenter` with a method that returns the exact associative array expected by `resources/views/comments/_thread.blade.php`. Add a readonly `ResearchContentCommentsViewData` (namespace `App\View\Research` or `App\View\DTO`—match existing app layout) that holds `hasComments`, resolved defaults (`commentsMode`, `commentsFormAction`, `commentsShowControls`), and a list of **section rows** for the research grid (each item: `id`, `content_html`, optional `thought`, plus precomputed `threadInclude` array and `commentCount` / label for mobile summary). Controllers build this object before `return view(...)`. Blade only iterates and `@include`s.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Pest, existing `ResearchCommentsPresenter` and `ShareContext`.

---

## File map (create / modify)

| Path | Role |
|------|------|
| `app/View/Presenters/Comments/ResearchCommentsPresenter.php` | Add `threadIncludeForSection(Thought $section, ...): array` (and any tiny helpers private to row counts if needed). |
| `app/View/Research/ResearchContentCommentsViewData.php` (new) | Readonly view data + named constructors `none()`, `forOwner(ResearchCommentsPresenter, Collection, ...)`. |
| `app/Http/Controllers/IdeaController.php` | Build view data for `research_show`; pass `researchContentComments` for `idea.show` when needed. |
| `app/Http/Controllers/SharedResearchViewController.php` | Build per-section thread props for `shared_research.readonly` (or a dedicated private method). |
| `resources/views/idea/partials/research_content.blade.php` | Remove `@php` assigns; consume `ResearchContentCommentsViewData`. |
| `resources/views/idea/research_show.blade.php` | Pass view data into partial; optionally replace `isset($commentsPresenter)` for page-level block with prebuilt props array from controller. |
| `resources/views/idea/partials/thought_detail_research_preview_card.blade.php` | Receive `researchContentComments` from parent; remove `@php` that builds `$researchPreviewSections`—move mapping to `ThoughtDetail` / controller / small builder called from controller only (see Task 6). |
| `resources/views/shared_research/readonly.blade.php` | No `@php` in loop; use controller-built structures. |
| `resources/views/shared_research/index.blade.php` | Remove inline `@php` for `$shareUrl` (pass from controller). |
| `resources/views/comments/_thread.blade.php` | Remove `@php` defaults; document that callers must pass `title` and `showControls` explicitly (update all `@include('comments._thread', ...)` call sites in repo). |
| `tests/Unit/ResearchCommentsPresenterTest.php` | New tests for `threadIncludeForSection`. |
| `tests/Unit/View/Research/ResearchContentCommentsViewDataTest.php` (new) | Tests for `none()` / `forOwner()` edge cases. |

**Out of scope for this plan (follow-up):** Broad `@php` removal across `idea/stream.blade.php`, `thought_detail_video_sidebar.blade.php`, etc., unless listed above. Optional lint script from spec is a later increment.

---

### Task 1: `ResearchCommentsPresenter::threadIncludeForSection`

**Files:**
- Modify: `app/View/Presenters/Comments/ResearchCommentsPresenter.php`
- Modify: `tests/Unit/ResearchCommentsPresenterTest.php`

- [ ] **Step 1: Write failing test**

Add to `ResearchCommentsPresenterTest`:

```php
public function test_thread_include_for_section_matches_manual_include_keys(): void
{
    $user = User::factory()->create();
    $root = Thought::factory()->create(['user_id' => $user->id]);
    $section = Thought::factory()->create([
        'user_id' => $user->id,
        'parent_id' => $root->id,
    ]);
    $presenter = new ResearchCommentsPresenter($root, $user, null);

    $props = $presenter->threadIncludeForSection(
        $section,
        formAction: 'https://example.test/comments',
        mode: 'owner',
        showControls: true,
        title: 'Section comments',
    );

    $this->assertSame('https://example.test/comments', $props['formAction']);
    $this->assertSame('thought', $props['commentableType']);
    $this->assertSame((string) $section->id, $props['commentableId']);
    $this->assertSame('owner', $props['mode']);
    $this->assertSame('Section comments', $props['title']);
    $this->assertTrue($props['showControls']);
    $this->assertIsArray($props['rows']);
}
```

Adjust key names to match what `_thread` expects (`commentableId` may be string in existing includes—match `research_content` today).

- [ ] **Step 2: Run test — expect failure**

Run: `php artisan test tests/Unit/ResearchCommentsPresenterTest.php --filter=test_thread_include`

Expected: FAIL (method not found).

- [ ] **Step 3: Implement `threadIncludeForSection`**

Public method signature (align with `_thread` docblock):

```php
/**
 * Props for @include('comments._thread', $props).
 *
 * @return array{
 *   rows: array<int, array<string, mixed>>,
 *   formAction: string,
 *   commentableType: string,
 *   commentableId: string,
 *   mode: string,
 *   disabledMessage: string|null,
 *   title: string,
 *   showControls: bool
 * }
 */
public function threadIncludeForSection(
    Thought $section,
    string $formAction,
    string $mode,
    bool $showControls,
    string $title,
    ?string $disabledMessage = null,
): array {
    return [
        'rows' => $this->sectionRowsFor($section),
        'formAction' => $formAction,
        'commentableType' => 'thought',
        'commentableId' => (string) $section->id,
        'mode' => $mode,
        'disabledMessage' => $disabledMessage,
        'title' => $title,
        'showControls' => $showControls,
    ];
}
```

Use `$disabledMessage` when comments are disabled: pass `'Comments are disabled.'` or null when allowed—callers decide, matching current Blade logic.

- [ ] **Step 4: Run test — expect pass**

Run: `php artisan test tests/Unit/ResearchCommentsPresenterTest.php --filter=test_thread_include`

- [ ] **Step 5: Commit**

```bash
git add app/View/Presenters/Comments/ResearchCommentsPresenter.php tests/Unit/ResearchCommentsPresenterTest.php
git commit -m "feat(comments): add threadIncludeForSection to ResearchCommentsPresenter"
```

---

### Task 2: `ResearchContentCommentsViewData` + `none()` / `forOwner()`

**Files:**
- Create: `app/View/Research/ResearchContentCommentsViewData.php`
- Create: `tests/Unit/View/Research/ResearchContentCommentsViewDataTest.php`

Design (adjust property names to match Blade needs):

- `public bool $hasComments`
- `public string $commentsMode`
- `public ?string $commentsFormAction` (null when no comments UI)
- `public bool $commentsShowControls`
- `/** @var list<object> $sectionItems */` — each object should expose: `id`, `content_html`, `?thought`, `threadInclude` (array|null), `mobileSummary` (`array{count: int, label: string}`|null) for the `<details>` summary line.

Implement `public static function none(): self` — all false/null/empty; `sectionItems` unused when `hasComments` is false (Blade should not read them).

Implement `public static function forOwner(ResearchCommentsPresenter $presenter, \Illuminate\Support\Collection $sections, ?string $formActionOverride = null): self` where:
- `hasComments` = true
- `commentsMode` = `'owner'`
- `commentsFormAction` = `$formActionOverride ?? route('comments.store')` (call `route()` inside the factory, not Blade)
- `commentsShowControls` = true
- For each `$sections` item: if `isset($item->thought)`, set `threadInclude` via `$presenter->threadIncludeForSection(...)` with titles `'Section comments'` / `'Comments'` matching current `research_content.blade.php` sidebar vs mobile; set `disabledMessage` using `! $presenter->canCommentOnSection($item->thought) ? 'Comments are disabled.' : null` (match existing strings).

- [ ] **Step 1: Write `ResearchContentCommentsViewDataTest`** with two tests: `none_has_no_comments`; `for_owner_builds_section_thread_include` (one section with child thought, assert `threadInclude['formAction']` equals `route('comments.store')`).

- [ ] **Step 2: Run tests — fail**

Run: `php artisan test tests/Unit/View/Research/ResearchContentCommentsViewDataTest.php`

- [ ] **Step 3: Implement class** (constructor private + static factories).

- [ ] **Step 4: Run tests — pass**

- [ ] **Step 5: Commit**

```bash
git add app/View/Research/ResearchContentCommentsViewData.php tests/Unit/View/Research/ResearchContentCommentsViewDataTest.php
git commit -m "feat(views): add ResearchContentCommentsViewData for research partials"
```

---

### Task 3: Migrate `research_content.blade.php`

**Files:**
- Modify: `resources/views/idea/partials/research_content.blade.php`

- [ ] **Step 1: Update docblock** at top to list `$researchContentComments` (`ResearchContentCommentsViewData`) as the contract; keep `$root_html`, `$sections` or replace iteration source with `$researchContentComments->sectionItems` when `hasComments`—simplest is **always** pass `$researchContentComments` and use `$sections` from view data when `hasComments`, else parent passes original `$sections`—**avoid dual sources**: prefer view data to expose `sectionsForDisplay` as a collection for the main column (merged in Task 4).

**Minimal behavior-safe approach for Task 3:**  
- When `hasComments`, loop `$researchContentComments->sectionItems` (built in factory from same sections).  
- When `!hasComments`, loop original `$sections` as today.

Blade structure:

- Replace opening `@php` with `@if($researchContentComments->hasComments)` using injected variable (always passed).
- Remove inner `@php` blocks; use `$item->threadInclude`, `$item->mobileSummary` for mobile block; sidebar uses same `threadInclude` as current duplicate (two loops can iterate the same `sectionItems` twice as today).

- [ ] **Step 2: Manual smoke** — load research page and a preview card route if available.

- [ ] **Step 3: Commit**

```bash
git add resources/views/idea/partials/research_content.blade.php
git commit -m "refactor(views): remove @php from research_content partial"
```

---

### Task 4: Wire `IdeaController` + `research_show` + preview card + `idea.show`

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (methods `show` and `researchShow` / exact names: `thoughts.show` vs `researchShow`)
- Modify: `resources/views/idea/research_show.blade.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_research_preview_card.blade.php`

- [ ] **Step 1: `researchShow` (research page)**  
After building `$commentsPresenter`, set:

```php
use App\View\Research\ResearchContentCommentsViewData;

// Section comments in partial stay off (same as today: no presenter passed before).
'researchContentComments' => ResearchContentCommentsViewData::none(),
```

If Task 3 requires `researchContentComments` always: pass `none()`. Pass the same to `@include('idea.partials.research_content', ...)`.

- [ ] **Step 2: `show` (thought detail)**  
Add to view array:

```php
'researchContentComments' => ResearchContentCommentsViewData::none(),
```

- [ ] **Step 3: `research_show.blade.php`**  
Pass `'researchContentComments' => $researchContentComments` into `research_content` include. For page-level `@if(isset($commentsPresenter))` block, **optional in this task**: build `$pageThreadProps` in controller using `$presenter->threadIncludeForPage(...)` — only add a `threadIncludeForPage` method if you deduplicate `pageLevelRows` + flags; otherwise leave `research_show` as-is for page block (still uses presenter in Blade). **Spec priority:** remove `@php` in `research_content` first; `research_show` page block can stay until a fast follow.

- [ ] **Step 4: Preview card**  
Move `@php` mapping of `section_html_chunks` out of the Blade. **Concrete location:** `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` already exposes `emailResearchPreview()` and `videoResearchPreview()` with `section_html_chunks`. Add something like `public function researchPreviewSectionsForCard(): \Illuminate\Support\Collection` that returns `collect($preview['section_html_chunks'] ?? [])->map(fn (string $html) => (object) ['content_html' => $html])` when a preview exists, or add a static helper on `ResearchContentCommentsViewData` accepting the `?array` preview and returning that collection—then **`thought_detail_research_preview_card.blade.php`** calls it with `$researchPreview` only if you keep Blade free of PHP (prefer building the collection in `ThoughtDetailPresenter` and passing `researchPreviewSections` from `show.blade.php` alongside the existing card props). Pick one call site so the partial receives `sections` + `researchContentComments` with no `@php`.

- [ ] **Step 5: Run feature tests**

Run: `php artisan test tests/Feature/ResearchShowTest.php tests/Feature/ThoughtShowPageTest.php` (or grep for relevant tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/research_show.blade.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_research_preview_card.blade.php app/View/... # plus any ThoughtDetail file touched
git commit -m "feat(views): pass ResearchContentCommentsViewData from controllers"
```

---

### Task 5: `SharedResearchViewController` + `readonly.blade.php`

**Files:**
- Modify: `app/Http/Controllers/SharedResearchViewController.php`
- Modify: `resources/views/shared_research/readonly.blade.php`

- [ ] **Step 1: In controller**, after `$commentsPresenter` and `$sectionsWithHtml`, build:

- `$sectionThreadRows` — for each section with `thought`, store `threadInclude` via `$commentsPresenter->threadIncludeForSection(...)` with `formAction` = `route('shared-research.comment', $share->token)`, `mode` = `'guest'`, `showControls` = false, `title` = `'Section comments'`, `disabledMessage` = guest message when `!$commentsPresenter->allowGuestComments()`.

- `$pageThreadInclude` — same keys as current `@include('comments._thread', [...])` at bottom (page-level).

Pass to view: `sectionThreadIncludes` (list aligned with `$sections` or keyed by `section->id`), `pageThreadInclude`.

- [ ] **Step 2: Update `readonly.blade.php`** to use passed arrays only; mobile summary: pass `commentCount` and `commentLabel` from controller (use `Str::plural` in PHP).

- [ ] **Step 3: Run tests** — `php artisan test --filter=SharedResearch` or existing shared research tests.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php resources/views/shared_research/readonly.blade.php
git commit -m "refactor(views): move shared research comment props to controller"
```

---

### Task 6: `shared_research/index.blade.php` `$shareUrl`

**Files:**
- Modify: `app/Http/Controllers/SharedResearchViewController.php` (index method)
- Modify: `resources/views/shared_research/index.blade.php`

- [ ] **Step 1:** Pass `shareUrl` from controller: `url(route('shared-research.show', $share->token))` for each row if applicable (match current Blade).

- [ ] **Step 2:** Remove `@php` line in index view.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php resources/views/shared_research/index.blade.php
git commit -m "refactor(views): pass share URL from controller for shared research index"
```

---

### Task 7: `comments/_thread.blade.php` defaults

**Files:**
- Modify: `resources/views/comments/_thread.blade.php`
- Modify: every file that `@include`s `comments._thread` (grep: `comments._thread`)

- [ ] **Step 1: Grep** `resources/views` and `app` for `_thread`.

- [ ] **Step 2:** Ensure each include passes `'title' => ...` and `'showControls' => ...` explicitly.

- [ ] **Step 3:** Remove `@php` block from `_thread`; update docblock.

- [ ] **Step 4: Commit**

```bash
git add resources/views/comments/_thread.blade.php resources/views/...
git commit -m "refactor(views): require explicit title/showControls for comment thread partial"
```

---

### Task 8: Verification + spec status

- [ ] **Step 1:** Run full unit suite: `php artisan test tests/Unit`

- [ ] **Step 2:** Run affected feature tests: `php artisan test tests/Feature`

- [ ] **Step 3:** Grep migrated paths for `@php`: `rg '@php' resources/views/idea/partials/research_content.blade.php resources/views/shared_research/ resources/views/comments/_thread.blade.php` — expect no assign-heavy blocks in scope.

- [ ] **Step 4:** Update `docs/superpowers/specs/2026-04-20-blade-templates-design.md` **Status** to `Implemented` (or `Superseded by plan`—pick one) and reference this plan.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-04-20-blade-templates-design.md
git commit -m "docs: mark blade templates design spec implemented"
```

---

## Plan self-review

| Spec section | Task coverage |
|--------------|---------------|
| Presenter-centered props | Tasks 1–2, 5 |
| No `@php` assigns in scope | Tasks 3–7 |
| Controllers own shape | Tasks 4–6 |
| Unit tests | Tasks 1–2, 5 (feature) |
| Migration order (research partial → shared → thread) | Task order matches |

**Placeholder scan:** No TBD steps; Task 4 Step 4 intentionally points to codebase search for `videoResearchPreview`—implementer must open that symbol and attach the mapping method to the correct class once located.

**Naming:** If `ResearchContentCommentsViewData` section item type grows, extract a `ResearchContentSectionItem` readonly class in the same PR or a follow-up—plan allows a private array shape first to ship faster.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-20-blade-templates-cleanup.md`. Two execution options:

**1. Subagent-driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach do you want?

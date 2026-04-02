# Shared long-form documents — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restrict document shares to MCP-aligned long-form types, rename user-facing copy to **Shared documents**, gate stream/index card Share menus, add Share on **thought detail**, and update the public readonly chrome — without renaming routes, models, or DB tables.

**Architecture:** Add `Thought::isShareableDocumentRoot()` as the single eligibility gate (root + `visibleInStream()` + source not email/jira + `metadata.type` matching allowed set via `ThoughtTypeNavigation::normalizeTypeKey` for `research` / `plan` / `meeting` plus explicit lowercase allowlist for `decision`, `dev`, `support`, `spec`). Reuse from `SharedResearchController::store`, presenters, and Blade. Load optional `ResearchShare` in `IdeaController::show` for the detail Share block. Extend `ThoughtDetailPresenter` with named optional args for share + eligibility flags.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Pest/PHPUnit feature tests, existing `ResearchShare` model.

**Spec:** `docs/superpowers/specs/2026-04-02-shared-longform-documents-design.md`

**User-facing label (fixed for this build):** **Shared documents** — use for nav, `@section('title')`, index `<h1>`, help, and session flash strings that currently say “research” in this flow.

---

## File map

| File | Role |
|------|------|
| `app/Models/Thought.php` | `isShareableDocumentRoot(): bool` |
| `app/Http/Controllers/SharedResearchController.php` | Eligibility check in `store`; neutral flash/error copy |
| `app/Http/Controllers/SharedResearchViewController.php` | Pass optional section label into readonly view |
| `app/Http/Controllers/IdeaController.php` | `show()`: eager-load or query `ResearchShare` for thought; pass to presenter |
| `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` | New optional props + accessors for share UI |
| `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php` | `documentShareEligible(): bool` (delegates to thought) |
| `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php` | Same accessor for index cards |
| `resources/views/idea/partials/thought_card_actions.blade.php` | Gate root Share block on `$documentShareEligible === true` |
| `resources/views/idea/stream_thoughts.blade.php` | Pass `documentShareEligible` into partial |
| `resources/views/idea/index_thought_cards.blade.php` | Pass `documentShareEligible` |
| `resources/views/idea/partials/ideas_list.blade.php` | Pass `documentShareEligible` if partial includes card actions |
| `resources/views/idea/partials/thought_detail_header.blade.php` or new partial | Share block for eligible roots |
| `resources/views/idea/show.blade.php` | Include share partial when eligible |
| `resources/views/shared_research/index.blade.php` | Titles/headings → Shared documents |
| `resources/views/shared_research/readonly.blade.php` | Replace hardcoded “Research” label; use passed display label |
| `resources/views/layouts/idea.blade.php` | Nav label |
| `resources/views/help.blade.php` | Help bullet |
| `tests/Unit/Models/ThoughtShareableDocumentTest.php` | New unit tests for eligibility matrix |
| `tests/Feature/SharedResearchControllerTest.php` | Metadata on thoughts; new rejection cases; flash strings |
| `tests/Feature/SharedResearchViewTest.php` | Optional: assert readonly heading if asserted today |
| `tests/Feature/ThoughtShowPageTest.php` | Detail page share block visible/hidden |
| `tests/Feature/StreamPageTest.php` or typed stream tests | Share link only when eligible (if coverage exists) |
| `tests/Feature/ProfileSettingsTest.php` | Update assertions if menu text changes |

---

### Task 1: Unit tests — `Thought::isShareableDocumentRoot`

**Files:**
- Create: `tests/Unit/Models/ThoughtShareableDocumentTest.php`
- Modify: `app/Models/Thought.php` (stub method returning `false` until Task 2)

- [ ] **Step 1: Create test class with `RefreshDatabase`**

Cover at least:

- Root + `source = web` + `metadata.type = research` + visible → **true** (set `is_visible_in_stream` if needed for factory defaults).
- Root + `metadata.type = meeting` / `meetings` / `plan` / `plans` → **true** (meetings alias).
- Root + `metadata.type = decision` / `dev` / `support` / `spec` → **true**.
- Child thought (`parent_id` set) → **false** even if type is research.
- `metadata.type = video` → **false**.
- `source = email` (with typical email row: set `is_visible_in_stream = true` if required) → **false**.
- `source = jira` → **false**.
- Root + `metadata` null or missing `type` → **false**.

Use `Thought::factory()->create([...])` and ensure `visibleInStream()` scope passes: for non-email thoughts the scope includes all non-email sources; email requires `is_visible_in_stream`. Default factory does not set `user_id` — tests must set `user_id` and `parent_id`.

- [ ] **Step 2: Run tests**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/Models/ThoughtShareableDocumentTest.php
```

Expected: FAIL until implementation matches cases.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Models/ThoughtShareableDocumentTest.php app/Models/Thought.php
git commit -m "test: Thought shareable document root eligibility"
```

---

### Task 2: Implement `Thought::isShareableDocumentRoot`

**Files:**
- Modify: `app/Models/Thought.php`

- [ ] **Step 1: Implement method**

Logic sketch:

1. If `parent_id !== null` → return `false`.
2. ` $sourceKey = ThoughtTypeNavigation::normalizeTypeKey($this->source);` — if `email` or `jira` → `false`.
3. Read `metadata.type` as string; empty/missing → `false`.
4. Lowercase trimmed value; if `video` → `false`.
5. If in `['decision','dev','support','spec']` → go to step 7.
6. Else ` $navKey = ThoughtTypeNavigation::normalizeTypeKey($typeRaw);` — if not in `['research','plan','meeting']` → `false`.
7. Return `Thought::query()->whereKey($this->id)->visibleInStream()->exists();`

Add `use App\Support\ThoughtTypeNavigation;` if not present.

- [ ] **Step 2: Run unit tests**

```bash
php artisan test tests/Unit/Models/ThoughtShareableDocumentTest.php
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Thought.php
git commit -m "feat(thought): shareable long-form document root eligibility"
```

---

### Task 3: `SharedResearchController::store` + flash copy

**Files:**
- Modify: `app/Http/Controllers/SharedResearchController.php`

- [ ] **Step 1: After visibleInStream check**, if `! $thought->isShareableDocumentRoot()`, redirect to `shared-research.index` with errors on `thought_id` (or a single `message`) explaining only **long-form capture documents** can be shared (not video/email/jira/generic).

- [ ] **Step 2: Update session strings**

- Duplicate share message: change **“This research is already shared…”** → **“This document is already shared; manage it below.”** (or equivalent using “Shared documents” context).

- [ ] **Step 3: Extend `SharedResearchControllerTest`**

- Every `Thought::factory()->create` used for successful store must include eligible `metadata` (e.g. `['type' => 'research']`) and `user_id`, `parent_id => null`.
- Add `test_store_rejects_video_root`: `metadata => ['type' => 'video']` → expect redirect + errors.
- Add `test_store_rejects_email_source`: `source => 'email', is_visible_in_stream => true` → rejected.
- Add `test_store_rejects_jira_source` (or email only if jira factory is heavy).
- Update assertion for duplicate-share flash to new string.

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/SharedResearchControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SharedResearchController.php tests/Feature/SharedResearchControllerTest.php
git commit -m "feat(shares): gate store to shareable document roots"
```

---

### Task 4: Presenters + `thought_card_actions` gating

**Files:**
- Modify: `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`
- Modify: `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php`
- Modify: `resources/views/idea/partials/thought_card_actions.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/partials/ideas_list.blade.php` (if it passes `thought_card_actions`)

- [ ] **Step 1: Add `documentShareEligible(): bool` to both presenters**

Return `$this->thought->isShareableDocumentRoot()` (method is safe on loaded thought).

- [ ] **Step 2: Update `thought_card_actions`**

At top: `@php $documentShareEligible = $documentShareEligible ?? false; @endphp`

Wrap the entire `@if ($isRootThought) … Share … @endif` block so it runs only when `$documentShareEligible` is true (still require `$isRootThought`).

- [ ] **Step 3: Pass flag from stream and index includes**

`stream_thoughts.blade.php`: add `'documentShareEligible' => $card->documentShareEligible()` to the `@include` for `thought_card_actions`.

`index_thought_cards.blade.php`: same.

`ideas_list.blade.php`: pass `'documentShareEligible' => $thought->isShareableDocumentRoot()` (or from row presenter if thoughts are wrapped — match existing variable names in that partial).

- [ ] **Step 4: Add/adjust feature test**

If `tests/Feature/StreamPageTest.php` or research stream test asserts Share link, add case: root **without** eligible type should **not** see `shared-research.index` create link. Eligible root **should** see it.

- [ ] **Step 5: Run targeted tests**

```bash
php artisan test tests/Feature/StreamPageTest.php tests/Feature/SharedResearchControllerTest.php
```

(Adjust file list to whatever tests fail/touch.)

- [ ] **Step 6: Commit**

```bash
git add app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php resources/views/idea/partials/thought_card_actions.blade.php resources/views/idea/stream_thoughts.blade.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/partials/ideas_list.blade.php tests/Feature/
git commit -m "feat(ui): gate stream/index share menu to shareable documents"
```

---

### Task 5: Thought detail — presenter, controller, Blade

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (`show` method)
- Modify: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
- Create: `resources/views/idea/partials/thought_detail_document_share.blade.php` (optional; or embed in header)
- Modify: `resources/views/idea/show.blade.php` or `thought_detail_header.blade.php`

- [ ] **Step 1: In `IdeaController::show`**, after authorizing thought:

  - `$documentShare = ResearchShare::where('thought_id', $thought->id)->where('user_id', $thought->user_id)->first();` (or `auth()->id()` if always owner view).
  - `$documentShareEligible = $thought->isShareableDocumentRoot();`

- [ ] **Step 2: Extend `ThoughtDetailPresenter::forShow`** with named parameters at the end:

  - `?ResearchShare $documentShare = null`
  - `bool $documentShareEligible = false`

  Add accessors: `documentShare(): ?ResearchShare`, `showDocumentShareBlock(): bool` — true when `$documentShareEligible && ! DemoMode::enabled()` (mirror header `editable` / demo rules).

- [ ] **Step 3: Blade**

  In `thought_detail_header` or immediately after tags row: if `$thoughtDetail->showDocumentShareBlock()`, include partial that renders:

  - No share: link `route('shared-research.index', ['create' => $thought->id])` with label **Share** or **Create share link**.
  - Has share: Copy / Open / Manage (same URLs as `thought_card_actions`).

  Pass `$thought` and `$thoughtDetail` into partial.

- [ ] **Step 4: Feature tests** in `tests/Feature/ThoughtShowPageTest.php`:

  - Eligible research root → assert see `shared-research.index` with `create=` query or path.
  - Video or plain web thought without type → assert **don’t** see create link.

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/IdeaController.php app/View/Presenters/Thoughts/ThoughtDetailPresenter.php resources/views/idea/ tests/Feature/ThoughtShowPageTest.php
git commit -m "feat(thought-detail): share block for long-form documents"
```

---

### Task 6: Rename user-facing copy + public readonly chrome

**Files:**
- Modify: `resources/views/shared_research/index.blade.php` (`@section('title')`, `<h1>`, any “research” strings in body)
- Modify: `resources/views/layouts/idea.blade.php` (account menu item)
- Modify: `resources/views/help.blade.php` (bullet: long-form docs, meetings, plans, specs, etc.)
- Modify: `resources/views/shared_research/readonly.blade.php`
- Modify: `app/Http/Controllers/SharedResearchViewController.php`

- [ ] **Step 1: Readonly view**

  Replace uppercase label **“Research”** with dynamic text:

  - Default **“Shared document”**.
  - If `ThoughtTypeNavigation::normalizeTypeKey($root->metadata['type'] ?? null)` returns `research`/`plan`/`meeting`, optionally show `ThoughtTypeNavigation::thoughtDisplayLabel($key)`; for `decision`/`dev`/`support`/`spec`, use `ucfirst` of metadata type.

  Pass from controller as `$documentTypeLabel` to avoid logic in Blade if preferred.

- [ ] **Step 2: `@section('title', …)`** on readonly: use “Shared document” + site suffix pattern consistent with minimal layout.

- [ ] **Step 3: Grep and fix tests**

```bash
rg -n "Shared research" tests resources/views
```

Update `ProfileSettingsTest` or others asserting menu text to **Shared documents** if applicable.

- [ ] **Step 4: Run full relevant suite**

```bash
php artisan test tests/Feature/SharedResearchControllerTest.php tests/Feature/SharedResearchViewTest.php tests/Feature/ProfileSettingsTest.php tests/Feature/ThoughtShowPageTest.php
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/ app/Http/Controllers/SharedResearchViewController.php tests/
git commit -m "feat(ui): Shared documents copy and readonly chrome"
```

---

### Task 7: Final sweep + demo/docs mention

- [ ] **Step 1: Run**

```bash
php artisan test
```

- [ ] **Step 2: If `docs/superpowers/plans/2026-03-17-shareable-research.md` is still the execution reference**, add a one-line note at top pointing to this plan for eligibility/UI changes (optional).

- [ ] **Step 3: Commit** (only if doc touch)

```bash
git add docs/superpowers/plans/2026-03-17-shareable-research.md
git commit -m "docs: point shareable research plan to long-form follow-up"
```

---

## Follow-up (not in this plan)

- Align `SharedResearchViewController` section list with `IdeaController::isStructuredDocumentSection` + `section_index` ordering when replies exist on document roots.

---

## Handoff

After all tasks: run `php artisan test` and manually smoke-test: create share from **Meetings** stream, open `/r/{token}`, open **thought detail** Share block, revoke from **Shared documents** index.

# Video detail email-parity layout — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the thought detail page for video thoughts to match the email thought layout (two-column grid on large screens, research preview under main content, transcript below preview, video metadata and actions in a right sidebar), and remove video chrome from the shared header card.

**Architecture:** Reuse the same `lg` grid and left-column stacking pattern as email in `resources/views/idea/show.blade.php`. Extract shared “linked research → markdown preview payload” logic in `IdeaController` so email behavior stays identical while video gains `buildVideoResearchPreview` via `metadata.research_thought_id`. New Blade partial `thought_detail_video_sidebar.blade.php` holds markup moved from `thought_detail_header.blade.php`. `ThoughtDetailPresenter` gains `videoResearchPreview` parallel to `emailResearchPreview`.

**Tech stack:** Laravel 12, Blade, Tailwind CSS 4, Pest/PHPUnit, existing `CommonMarkConverter` + `renderDemoSafeMarkdown` patterns.

**Specification:** `docs/superpowers/specs/2026-04-02-video-detail-email-parity-design.md`

---

## File map

| File | Role |
|------|------|
| `app/Http/Controllers/IdeaController.php` | Refactor `buildEmailResearchPreview` to delegate to a shared private method; add `resolveVideoLinkedResearchThought` (or equivalent) + `buildVideoResearchPreview`; pass `videoResearchPreview` into `ThoughtDetailPresenter::forShow`. |
| `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` | New constructor/`forShow` arg `?array $videoResearchPreview`, accessor `videoResearchPreview(): ?array`. |
| `resources/views/idea/show.blade.php` | Two-column layout when email **or** video; left column order: content → video research preview (if any) → transcript (if any); include video sidebar. |
| `resources/views/idea/partials/thought_detail_header.blade.php` | Remove video-only inner block (metadata, actions, related email); keep rose border + `data-thought-detail-kind="video"` on header root. |
| `resources/views/idea/partials/thought_detail_video_sidebar.blade.php` | **Create:** video metadata rows; footer row for **View research** link + **text-only** status (`Research pending`); **Actions** subsection with **all POST controls** (fetch transcript, research now, rerun) using the same full-width button styling as email — **do not** duplicate those forms in a second “link row” above (email sidebar has a single Actions area, not two copies of the same CTAs). Related email block when present. |
| `tests/Feature/ThoughtShowPageTest.php` | Update assertions that expect “Video metadata” inside header; add ordering + preview tests per spec §6. |
| `tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php` | Pass `videoResearchPreview: null` (or new cases). |

---

### Task 1: Research preview payload (controller + presenter)

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (`show`, private helpers near `buildEmailResearchPreview`)
- Modify: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
- Modify: `tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php`

- [ ] **Step 1: Write a failing feature test for video research preview data**

Primary test: extend **`ThoughtShowPageTest`** — GET `thoughts.show` for a video with `research_thought_id` set to an owned research root with non-empty markdown; assert `view('idea.show', ...)` or `$response->viewData('thoughtDetail')->videoResearchPreview()` is non-null and has keys `full_research_url`, `root_html`, `section_html_chunks`. Follow factory patterns near `test_video_thought_detail_shows_transcript_status_canonical_link_and_view_research` (~1620). **Expect failure** until presenter and controller are wired. (Optional extra: unit test on `ThoughtDetailPresenter::forShow` with a stub `videoResearchPreview` array — not required if the feature test covers the controller path.)

Run:

```bash
cd /Users/rosstweedie/Sites/ideatub && ./vendor/bin/pest tests/Feature/ThoughtShowPageTest.php --filter=<your_new_test_name>
```

Expected: **FAIL** (preview null or missing view data).

- [ ] **Step 2: Extract shared preview builder from email path**

In `IdeaController`, introduce a private method, e.g. `buildResearchPreviewPayloadFromLinkedResearchThought(?Thought $linkedResearch, string $markdownContextPrefix): ?array`, that:

1. Returns `null` if `$linkedResearch === null`.
2. Calls existing `resolveResearchDocumentRootForPreview($linkedResearch)`.
3. Validates research root with the same `Thought::query()->whereKey(...)->where('user_id', auth()->id())->matchingCanonicalMetadataType('research')->exists()` pattern as today’s `buildEmailResearchPreview`.
4. Renders root + first two child sections via `CommonMarkConverter` and `renderDemoSafeMarkdown`, using context keys:
   - For prefix `'email'`: `email_research_preview_root` / `email_research_preview_section` (unchanged behavior).
   - For prefix `'video'`: `video_research_preview_root` / `video_research_preview_section` (new demo boundaries).

5. Calls existing `researchEmailPreviewHasRenderableBody` (optionally rename to `researchPreviewHasRenderableBody` in the same refactor if you touch all call sites in-file).

Refactor `buildEmailResearchPreview` to: resolve email linked thought → `buildResearchPreviewPayloadFromLinkedResearchThought($resolved, 'email')`.

Run:

```bash
./vendor/bin/pest tests/Feature/ThoughtShowPageTest.php --filter=email
```

(or a narrower filter for tests that cover email research preview if present) — expected: **PASS** (no email regression).

- [ ] **Step 3: Resolve video-linked research and build video preview**

Add `private function resolveVideoLinkedResearchThought(Thought $thought): ?Thought`:

- Return `null` unless `data_get($thought->metadata, 'type') === 'video'` (same rule as `ThoughtDetailPresenter::isVideoThought()`).
- `normalizeResearchThoughtId(data_get($thought->metadata, 'research_thought_id'))`, load with `where('user_id', auth()->id())` and `matchingCanonicalMetadataType('research')` — same trust model as `resolveEmailLinkedResearchThought`’s final query.

Add `private function buildVideoResearchPreview(Thought $video): ?array` → delegate to `buildResearchPreviewPayloadFromLinkedResearchThought($this->resolveVideoLinkedResearchThought($video), 'video')`.

In `show()`, compute `$videoResearchPreview = $this->buildVideoResearchPreview($thought)` only when the thought is a video root, using **the same predicate as** `ThoughtDetailPresenter::isVideoThought()` (`data_get($thought->metadata, 'type') === 'video'`). Add a one-line comment pointing to that presenter method so the two stay in sync; if duplication becomes annoying later, extract a shared static helper on the presenter. Pass into `ThoughtDetailPresenter::forShow(..., videoResearchPreview: $videoResearchPreview)`.

- [ ] **Step 4: Extend `ThoughtDetailPresenter`**

Add property + constructor arg + `forShow` parameter `private readonly ?array $videoResearchPreview` and `public function videoResearchPreview(): ?array`. Update `ThoughtDetailPresenterTest` `forShow` call with `videoResearchPreview: null`.

Run:

```bash
./vendor/bin/pest tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php
./vendor/bin/pest tests/Feature/ThoughtShowPageTest.php --filter=<your_new_test_name>
```

Expected: **PASS** for unit test; new feature test **PASS** if it only asserts presenter data. (Blade will not show preview yet — optional second assertion on visible “Research preview” can wait for Task 2.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IdeaController.php app/View/Presenters/Thoughts/ThoughtDetailPresenter.php tests/
git commit -m "feat(thought-detail): add video research preview payload shared with email builder"
```

---

### Task 2: Blade layout and video sidebar (single cohesive change)

**Files:**
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Create: `resources/views/idea/partials/thought_detail_video_sidebar.blade.php`

- [ ] **Step 1: Failing feature tests for layout (DOM order + header scope)**

In `ThoughtShowPageTest`:

1. **Update** `test_video_thought_detail_shows_transcript_status_canonical_link_and_view_research` (and any test that asserts “Video metadata” or `route('videos.store')` on the whole response): scope assertions — e.g. parse with Symfony DomCrawler: select `[data-thought-detail-kind="video"]` and assert it does **not** contain the text “Video metadata”. Assert `[data-thought-detail-sidebar="video"]` contains “Video metadata” and the expected `route('videos.store')` when actions should show.

2. Add **`test_video_thought_detail_left_column_order_content_research_preview_transcript`**: video with linked research (preview non-empty) + transcript child → crawl **`[data-thought-detail-main]`** (the left column wrapper) and assert heading order: **Content** → **Research preview** → **Transcript**.

3. Add **`test_video_thought_detail_transcript_directly_below_content_when_no_research_preview`**: video with transcript, no `research_thought_id` (or research not renderable) → no “Research preview” heading; “Transcript” appears after “Content” in the main column.

4. Add **`test_video_thought_detail_no_preview_or_transcript_cards_when_absent`**: minimal video thought → 200, no optional cards.

Run pest on these tests — expect **FAIL** until Blade is updated.

- [ ] **Step 2: Implement `thought_detail_video_sidebar.blade.php`**

Move the removed header markup into a new partial, **email-style single pass for CTAs:**

- **Video metadata** heading + `video_metadata_labeled_rows`.
- **Footer row** (no POST duplication here): `View research` link when URL present; plain text for `Research pending` when shown.
- **Actions** subsection (`border-t`, uppercase “Actions” label): **only place** for POST forms — Fetch transcript, Research now, Rerun research — same `action`, `@csrf`, and hidden inputs as the current header had; use button styling consistent with `thought_detail_email_sidebar` (full-width bordered buttons).
- **Related email** block when `relatedEmailCard()` is non-null (same copy as current header).

Set `data-thought-detail-sidebar="video"` on the root `<aside>`.

- [ ] **Step 3: Strip video block from `thought_detail_header.blade.php`**

Delete lines 20–69 (the `@if (isVideoThought())` inner content) per spec; keep rose border and `data-thought-detail-kind="video"`.

- [ ] **Step 4: Rework `show.blade.php`**

- Define `@php $useThoughtDetailTwoColumn = $isEmailThought || $thoughtDetail->isVideoThought(); @endphp`
- Apply grid classes when `$useThoughtDetailTwoColumn` (same string as current email grid).
- Open left column wrapper when `$useThoughtDetailTwoColumn`.
- **Reorder** video-only blocks:
  - Keep single **Content** article first for non-email thoughts.
  - If `$thoughtDetail->isVideoThought() && videoResearchPreview()` present, render **Research preview** article (duplicate the email block structure but use `videoResearchPreview()` keys — same partial `idea.partials.research_content`).
  - Then **Transcript** article if `videoTranscriptText()` (move the transcript `@if` block to after the video research preview `@if`).
- Close left column; `@include` email sidebar when `$isEmailThought`'; `@include` video sidebar when `$thoughtDetail->isVideoThought()` with `thought` + `thoughtDetail`.
- On the **left column** `div.space-y-6.min-w-0` that wraps the main stack, add **`data-thought-detail-main`** (boolean attribute, no value needed) whenever `$useThoughtDetailTwoColumn` is true — **both** email and video, so tests use one selector for both layouts.

- [ ] **Step 5: Run targeted then full tests**

```bash
./vendor/bin/pest tests/Feature/ThoughtShowPageTest.php
./vendor/bin/pest tests/Unit/View/Presenters/Thoughts/ThoughtDetailPresenterTest.php
```

Expected: **PASS**.

- [ ] **Step 6: Commit**

```bash
git add resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_header.blade.php resources/views/idea/partials/thought_detail_video_sidebar.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat(thought-detail): video layout parity with email (grid, sidebar, preview order)"
```

---

### Task 3: Verification and regression sweep

- [ ] **Step 1: Full Pest run**

```bash
cd /Users/rosstweedie/Sites/ideatub && ./vendor/bin/pest
```

Expected: all green.

- [ ] **Step 1b: Pint (optional but recommended if PHP changed)**

This repo uses Laravel Pint, not PHPStan.

```bash
cd /Users/rosstweedie/Sites/ideatub && ./vendor/bin/pint --test
```

Fix style with `./vendor/bin/pint` if `--test` fails.

- [ ] **Step 2: Manual smoke test**

Open a video thought with research + transcript in the browser: confirm header shows tags only, sidebar shows metadata/actions, left column order matches spec.

- [ ] **Step 3: Final commit if fixes needed**

Only if Step 1 or 2 required code changes.

---

## Notes for implementers

- **Single PR / same commit series:** Per design spec §8, avoid merging Task 2 Blade changes without the sidebar (Task 2 steps 2–4 land together).
- **Email regression:** After refactors, run at least one email thought detail test if available (search `ThoughtShowPageTest` for `email` + `show`).
- **@skills:** Implementation execution should follow @superpowers:subagent-driven-development or @superpowers:executing-plans; verification before claiming done: @superpowers:verification-before-completion.

---

## Plan review

- After edits to this plan file, run the plan-document review loop described in @superpowers:writing-plans (reviewer should receive paths to this plan and the spec only).

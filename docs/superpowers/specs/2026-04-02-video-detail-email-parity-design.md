# Video detail page — parity with email detail layout

**Date:** 2026-04-02  
**Status:** Draft (brainstorming output; implementation follows separate plan)  
**Scope:** Restructure the thought detail page for **video** thoughts so it mirrors the **email** thought layout: shared top header with tags, two-column main area on large screens (primary content left, metadata and actions right), and **research preview** on the left under the main content card.

**Chosen approach:** **Approach 1** — extend `resources/views/idea/show.blade.php` with the same grid pattern used for email, add a dedicated video sidebar partial, and remove video-specific chrome from the shared header partial. No new generic “layout engine” or presenter-driven section arrays in this slice.

---

## 1. Goals

- Video thoughts use the **same structural layout** as email thoughts:
  - Full-width **thought detail** header card: type, relative time, tags, edit (and existing non-video-only header behavior).
  - **`lg` two-column grid**: left column ~2/3, right ~1/3, matching existing Tailwind classes for email (`grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start`).
- **Left column** (stacked cards, top to bottom):
  1. **Content** — existing main body card (prose / embed + document sections).
  2. **Research preview** — when a linked research document exists and preview HTML is non-empty, same UX as email: `research_content` partial + “View full research” link, same card chrome as today’s email research preview block.
  3. **Transcript** — when transcript text exists, **below** research preview (user choice **B**). If there is no research preview, transcript remains directly under content.
- **Right column**: sidebar for **video metadata** (labeled rows), **research / transcript actions** (links and small forms mirroring current header behavior), and **related email** block when present — structured to **mirror the email sidebar** (`thought_detail_email_sidebar.blade.php`): one aside with a metadata section, footer/status row where appropriate, then an **Actions** subsection (border separator) for primary CTAs (fetch transcript, research now, rerun research), consistent with email’s “Actions” block pattern.
- **Replies** remain full-width **below** the grid, unchanged.

## 2. Non-goals

- Changing stream cards, capture flows, or video/research jobs.
- Redesigning typography or color system beyond moving blocks; optional minor alignment (e.g. metadata label accent) is implementation detail.
- Merging email and video into a single Blade partial for both sidebars in this slice (YAGNI unless duplication becomes painful).

## 3. Current vs desired behavior

| Area | Current (video) | Desired |
|------|------------------|---------|
| Header (`thought_detail_header.blade.php`) | Tags + large block: video metadata rows, view research / transcript / research actions, related email | Same **shell** as email: tags only (+ existing idea completion, etc.); **no** video metadata or actions in header |
| Main layout | Single column | Same **grid** as email on `lg+` |
| Research | Link to full research only | **Inline preview** when available (same shape as email preview) |
| Transcript | Card under content | Card **after** research preview card when preview shown |

## 4. Backend and presenter

- **`IdeaController::show`**: When the thought is a video root (`metadata.type === 'video'` or existing `ThoughtDetailPresenter::isVideoThought()` criteria), build an optional preview payload **reusing the same semantics** as `buildEmailResearchPreview`: resolve linked research thought (from `metadata.research_thought_id`), resolve document root for preview, render root + up to two section chunks with `CommonMarkConverter` and existing demo-safe markdown helpers, return `null` if not renderable. Prefer **extracting a small private helper** shared by email and video preview builders if it reduces duplication (e.g. `buildResearchPreviewForThought(Thought $researchRoot): ?array`), without changing email behavior.
- **`ThoughtDetailPresenter`**: Add optional `videoResearchPreview` (same array shape as `emailResearchPreview`) and `videoResearchPreview(): ?array` accessor. Thread through `forShow` factory and tests.
- Existing methods (`videoMetadataLabeledRows`, `videoLatestResearchUrl`, `showFetchTranscriptAction`, `showVideoResearchPending`, etc.) remain the **source of truth** for sidebar visibility; the Blade partial consumes them.

## 5. View changes

- **`show.blade.php`**: Introduce a condition equivalent to “use two-column layout” for **email OR video** (e.g. `$isEmailThought || $thoughtDetail->isVideoThought()`). Wrap left column stack and sidebar include accordingly. For video: left stack order = content → research preview (if any) → transcript (if any). Include new `idea.partials.thought_detail_video_sidebar` with `$thought`, `$thoughtDetail`.
- **`thought_detail_header.blade.php`**: Remove the `@if (isVideoThought())` block that renders metadata, actions, and related email (lines ~20–68 in current structure). Related email moves to video sidebar only.
- **New** `thought_detail_video_sidebar.blade.php`: Metadata labeled rows, link/form row, related email card, then Actions subsection — mirror spacing and heading styles from `thought_detail_email_sidebar.blade.php` for consistency.

## 6. Testing

- Extend **feature** coverage for thought show (e.g. `ThoughtShowPageTest`): video thought renders **without** video metadata strings inside the header region (assert absence of duplicate content or use stable `data-` attributes if added for tests); when research preview is buildable, left column contains research preview before transcript when both exist; sidebar contains expected actions when fixtures allow.
- **Unit** tests for `ThoughtDetailPresenter` if constructor/factory gains `videoResearchPreview` (update existing tests that construct the presenter).

## 7. Accessibility and responsive

- Preserve existing focus and form semantics for POST actions moved to sidebar.
- Single-column stack on small screens: same DOM order as email (main stack, then sidebar, then replies).

## 8. Implementation sequencing (informal)

1. Controller + presenter + tests for preview payload.  
2. New sidebar partial + header cleanup.  
3. `show.blade.php` grid and card ordering.  
4. Full test run and manual spot-check on a video with research + transcript.

---

## 9. Approval record

- **Layout / transcript order:** User confirmed transcript **below** research preview when both exist.  
- **Implementation approach:** User confirmed **Approach 1** (mirror email in `show` + new video sidebar partial; no shared layout abstraction in this slice).

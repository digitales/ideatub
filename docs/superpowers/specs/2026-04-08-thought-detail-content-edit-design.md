# IdeaTub — Edit thought content on the detail page (rendered read + `content_html`)

**Date:** 2026-04-08  
**Status:** Approved (design)  
**Depends on:** [2026-03-21-edit-thought-content-design.md](./2026-03-21-edit-thought-content-design.md) (card inline edit, `ideas.update-content`, `thoughtContentEditor`)

## Overview

- **Goal:** Let owners fix typos and markdown on the **full thought detail page** (`thoughts.show`), where body text is shown as rendered HTML, without a full page reload after save.
- **Non-goal (v1):** Editing email-import body text, video transcript blocks, or adding `PATCH` to the OAuth `/api/thoughts` REST API.
- **Non-goal (v1):** Recomputing embeddings after manual content edits (same limitation as card edit today; semantic search may stay briefly stale).

## 1. Problem

Card surfaces (Stream, Ideas, index) already support inline markdown editing via `editable_thought_content` and `PATCH /ideas/{thought}/content`. The detail page renders markdown as HTML in the **Content** article but does not expose that editor, so long-form reading happens on a page where the source cannot be corrected.

Chunked documents store the root body plus **section** rows as child `Thought` records (`source_metadata.section_index` / `section_title`). The detail page renders root HTML followed by section HTML in order; each piece must be editable from that page.

## 2. Backend

### 2.1 Endpoint

- Reuse **`ideas.update-content`** and **`IdeaController::updateContent`** — no new route.
- Authorization, validation, and persistence stay as today (`content` required after trim, non-empty, max 65535; only `content` column updated; tags and metadata unchanged).

### 2.2 JSON response shape

- For responses that already return JSON (`expectsJson() || ajax()`), extend the success payload from `{ "content": "..." }` to:

```json
{ "content": "...", "content_html": "<p>...</p>" }
```

- **`content_html`** MUST be produced **only on the server** from the saved markdown string, using the same markdown pipeline as `IdeaController::show`:
  - `CommonMarkConverter` with `html_input` => `strip`, `allow_unsafe_links` => false
  - `renderDemoSafeMarkdown()` with the **same context key** as `show()` for that thought:
    - Root thought: `thought_content`
    - Structured section child: `thought_content_section`
- Non-JSON success responses (redirect back with flash) do not need `content_html`.

## 3. Detail page data

### 3.1 Editable blocks

- In `IdeaController::show`, for non-email thoughts, build an **ordered list** of blocks aligned with current rendering:

  1. **Root:** the primary `$thought` and its rendered HTML (`$contentHtml` as today).
  2. **Sections:** each comment that satisfies `isStructuredDocumentSection` (has `parent_id`, non-empty `section_index` in `source_metadata`), sorted by `(int) section_index`, with HTML produced the same way as today’s `documentSectionHtmlChunks` loop.

- Each block exposes at minimum: the **`Thought` model** (for route binding and raw `content`), **initial `content_html` string**, and whether the UI is **editable** (owner and not demo mode — mirror `thought_detail_header` / tag row demo rules).

### 3.2 Email and other types

- **Email** thoughts: keep the existing plain **Email body** block; do not add this editor there.
- **Video** transcript article: out of scope for v1 (unchanged).

## 4. Frontend

### 4.1 Blade

- Replace the single prose container that concatenates root `{!! contentHtml !!}` and section chunks with a **`@foreach` over the block list**, each rendering a shared partial (e.g. `thought_detail_editable_block.blade.php`).
- The partial wraps read vs edit UI and receives per-block props: thought, initial HTML, raw markdown for the textarea, `update` URL (`route('ideas.update-content', $thought)`), `editable` flag, and optional **section label** (e.g. from `source_metadata.section_title` for sections; root may use a fixed label like “Content” or reuse the existing article heading pattern).

### 4.2 Alpine (`thoughtContentEditor`)

- **Extend** `resources/js/app.js` `thoughtContentEditor` with an optional **detail read mode** (exact flag name left to implementation; e.g. `detailMarkdownRead` or `readAsHtml`):
  - When enabled: **read** state shows server HTML via **`x-html="contentHtml"`** inside the same **prose** utility classes used on the detail page today (trusted server output only).
  - **Edit** state: unchanged textarea + Save / Cancel + Escape to cancel.
  - **Save success:** assign `content`, `originalContent`, and `draftContent` from `data.content`; assign **`contentHtml`** from `data.content_html` (required in this mode for correct read view).
- Card/list surfaces keep current behavior: they may ignore `content_html` on the wire and continue using plain-text read via `x-text`.

### 4.3 Edit affordance

- Each block exposes an **Edit** control visible only when `editable` is true (e.g. small button in the block header row). Do not rely on the card ⋮ menu, which is absent on the detail page.

## 5. Security

- **`content_html` in JSON** is server-generated from markdown after save. The client must not send HTML for storage in this flow.
- Markdown conversion settings remain strict (`html_input` strip, unsafe links disallowed), consistent with `show`.

## 6. Testing

- **Feature / JSON:** Extend `tests/Feature/UpdateThoughtContentTest.php` (or add a focused test) to assert a successful JSON update returns **`content_html`** and that trivial markdown produces expected HTML structure (e.g. a `<p>` wrapper).
- **Detail page:** Add or extend a feature test (e.g. `ThoughtShowPageTest`) so an **owner** sees the new **Edit** affordance on the Content block(s); a non-owner does not; **demo mode** hides edit controls for covered pages (consistent with existing detail/tag assertions).

## 7. Out of scope / follow-ups

- Re-embedding or background jobs to refresh vectors after `updateContent`.
- OAuth `/api/thoughts` update tool for external clients.
- Rich editor (toolbar, split preview) beyond textarea + rendered read.
- Editing unstructured **reply** comments from the detail replies list (only structured document sections in the main Content article are in scope).

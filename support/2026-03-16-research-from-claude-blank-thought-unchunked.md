# Research from Claude: blank thought + unchunked linked entry — Customer Support Investigation

**Date**: 2026-03-16
**Status**: Resolved
**Customer**: User report (support)
**Priority**: High
**Reported By**: Customer

## Issue Description

When adding a research piece from Claude (e.g. “please add this as a research piece to IdeaTub”), the result in IdeaTub was:

1. A **blank thought** with tags and a **linked entry** that was **not chunked** (one big thought).
2. User expectation: the research should be **shown as a research piece** and **at top level** (with content visible and sections chunked).

Example: Claude reported “I've added the Made by ON competitive profile to IdeaTub … auto-chunked it into 14 sections.” In IdeaTub the user saw a top-level card labelled “Research” with many tags but no visible body, and a single linked thought containing the full document.

## Customer Impact

- **Users affected**: Anyone saving long research documents to IdeaTub via Claude/Cursor using `capture_plan` with `doc_type=research`.
- **Severity**: High — research appears as an empty card with one giant child; poor discoverability and readability.
- **Business impact**: Core “save research to IdeaTub” use case was broken for long-form research.

## Investigation Steps

1. **Capture flow**: Confirmed `capture_plan` can either (a) receive full content and let the server chunk (root + section children), or (b) receive one call per section with optional `parent_id`. When `parent_id` is set, the server **never** chunks (single thought only).
2. **Chunking logic**: In `ThoughtChunkingService::splitAtHeadings()`, content is split at every ATX heading (`#`–`######`). If the document **starts** with a level-1 heading (e.g. `# Made by ON - Competitive Profile`), the “content before the first heading” is empty, so the **first section** was stored with empty content and used as the **root thought** → blank card in Stream.
3. **Alternative cause**: If Claude created a “root” thought first (e.g. with title-only or empty content) and then sent the full research in a **single** `capture_plan` call with `parent_id` set, the server would create one unchunked child (full document). That would also produce “blank root + one linked unchunked entry.”

## Root Cause Analysis

1. **Blank root**: For documents that start with a `#` heading, `splitAtHeadings()` produced an empty first section. The root thought was assigned this section, so the Stream card had no visible content.
2. **Unchunked linked entry**: Either (a) Claude sent the full document once with `parent_id` (so the server did not chunk), or (b) chunking did run but the root was blank and the rest of the content was in child sections — user may have perceived the first visible child as “one linked entry” if the UI emphasized it. The server-side fix ensures the root is never blank when we do chunk.

## Resolution

### 1. Merge empty first section when document starts with a heading (server)

**File:** `app/Services/ThoughtChunkingService.php`

After building sections, if the first section has empty content and there is at least one more section, merge the first section into the second (so the root thought gets the first real content). This way the Stream card for the research document always shows the title block (e.g. `# Made by ON - Competitive Profile` and metadata) instead of a blank.

**Tests:** `tests/Unit/Services/ThoughtChunkingServiceTest.php` — added `split_at_headings_merges_empty_first_section_when_doc_starts_with_heading`.

### 2. Clarify research sync instructions (avoid blank root + single child)

**Files:** `CLAUDE.md`, `.cursor/rules/ideatub-sync-research.mdc`

- **Preferred**: Send the **full research content in a single** `capture_plan` call **without** `parent_id`. The server will auto-chunk by headings and the root will now have content (after the merge fix).
- **Alternative**: Call `capture_plan` once per section **without** creating a root first; do **not** send the full document as one call with `parent_id`, or the server will store one unchunked thought and the “root” may appear blank.

### 3. “Shown as research” and “top level”

- **Research label**: Thoughts from `capture_plan` with `doc_type=research` already have `source=research`, so Stream shows them as “Research.” No change needed.
- **Top level**: Chunked documents have a top-level root and section children; the root is top-level. No change needed.

## Prevention & Follow-up

- [x] Merge empty first section in chunking (done).
- [ ] Consider adding a short note in the MCP tool description or Help that for long research, send full content in one call (no `parent_id`) for best chunking.

## Related Issues

- `support/2026-03-13-stream-document-tags-missing-children.md` — Stream tag filter and full section display for document roots.

## References

- `app/Services/ThoughtChunkingService.php` — `splitAtHeadings()`, merge of empty first section.
- `app/Services/ThoughtCaptureService.php` — `createChunked()`, root uses first section content.
- `CLAUDE.md` — IdeaTub: Save research via capture_plan.
- `.cursor/rules/ideatub-sync-research.mdc` — Cursor rule for saving research.

# Thoughts losing newlines / reduced spacing — Customer Support Investigation

**Date**: 2026-03-12
**Status**: Resolved
**Priority**: Medium
**Reported By**: Customer (internal)

## Issue Description

Thoughts displayed on IdeaTub were losing all newlines; spacing was reduced and multi-line content (e.g. bullet lists, paragraph breaks) appeared as a single block of text.

## Customer Impact

- Readability of stored thoughts (especially conversation summaries and structured notes) was degraded.
- Bullet points and paragraph breaks from MCP/Custom GPT captures were not visible.

## Investigation Steps

1. Located where thought content is rendered: `resources/views/idea/index.blade.php` and `resources/views/idea/stream.blade.php`.
2. Identified that content was output inside a single `<p>` with `{{ e($thought->content) }}`. HTML collapses consecutive whitespace (including newlines) inside block elements, so stored newlines were present in data but not displayed as line breaks.

## Root Cause Analysis

HTML default `white-space` behavior normalizes newline characters to spaces when rendering. The application was not preserving line breaks in the UI.

## Resolution

Added Tailwind class `whitespace-pre-line` to the paragraphs that display thought and comment content so that:

- Newline characters in the stored text are rendered as line breaks.
- Long lines still wrap normally (unlike `pre`, which would require horizontal scroll).
- Content remains escaped with `e()` (no XSS risk).

**Files updated:**

- `resources/views/idea/index.blade.php`: thought content paragraph and nested comment content paragraph.
- `resources/views/idea/stream.blade.php`: thought content paragraph and nested comment content paragraph.

## Prevention & Follow-up

- [ ] None required; fix is CSS-only and preserves existing escaping.

## References

- Views: `resources/views/idea/index.blade.php`, `resources/views/idea/stream.blade.php`

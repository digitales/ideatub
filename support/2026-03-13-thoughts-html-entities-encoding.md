# Thoughts showing HTML entities (e.g. &quot;, &#039;) — Customer Support Investigation

**Date**: 2026-03-13  
**Status**: Resolved  
**Priority**: Medium  
**Reported By**: Internal  

## Issue Description

When viewing thoughts (idea index and stream), some thought content was displayed with literal HTML entities instead of the intended characters. For example:
- `&quot;` appeared instead of `"`
- `&#039;` appeared instead of `'`

Example: a video summary thought showed text like `Video summary: &quot;12 Predictions...&quot;` and `don&#039;t` instead of proper quotes and apostrophes.

## Customer Impact

- Any user whose thoughts were captured from sources that pre-escape for HTML/JSON (e.g. AI summaries, API responses, pasted content from some tools).
- Medium severity: content is still readable but looks broken and unprofessional.

## Investigation Steps

1. Located thought display in `resources/views/idea/index_thought_cards.blade.php` and `resources/views/idea/stream_thoughts.blade.php` — both use `e($thought->content)` for safe HTML output.
2. Confirmed content is stored as-is in the `thoughts.content` column; no decoding is applied when storing (content comes from web form, MCP, Postmark inbound, etc.).
3. Identified root cause: some content is stored with HTML entities already present (e.g. from an API or tool that escaped quotes). The views escape for XSS with `e()` but do not decode existing entities first, so the user sees the literal entity strings.

## Root Cause Analysis

Thought content is sometimes persisted with HTML entities already in the string (e.g. `&quot;`, `&#039;`). This can happen when:
- Content is pasted from or synced from systems that escape for HTML/JSON.
- AI or integration responses return pre-escaped text.

The Blade templates correctly use `e()` to escape for safe HTML output, but they do not decode existing entities before escaping. So stored `&quot;` is output as `&quot;` and the browser displays the literal characters `&quot;` instead of rendering a quote.

## Resolution

1. **Thought model**: Added `getDecodedContent(): string` that returns `html_entity_decode($this->content, ENT_QUOTES | ENT_HTML5, 'UTF-8')` so all display code can decode-then-escape consistently.
2. **Views**: Updated thought and comment content output to use `e($thought->getDecodedContent())` (and equivalent for parent preview and comments) so that:
   - Stored entities are decoded to real characters.
   - Then `e()` re-escapes for safe HTML, so output remains XSS-safe.

Files changed:
- `app/Models/Thought.php` — added `getDecodedContent()`.
- `resources/views/idea/index_thought_cards.blade.php` — use decoded content for thought, parent preview, and comments.
- `resources/views/idea/stream_thoughts.blade.php` — use decoded content for thought and comments.

## Prevention & Follow-up

- Consider normalizing content on ingest (decode then store plain text) so the database holds canonical plain text. Current fix is display-only and avoids a data migration.
- If adding new thought content display surfaces (e.g. API responses, exports), use the same decode-then-escape pattern.

## References

- Views: `resources/views/idea/index_thought_cards.blade.php`, `resources/views/idea/stream_thoughts.blade.php`
- Model: `app/Models/Thought.php`

# Thoughts still showing HTML entities (e.g. &#039;) — Follow-up

**Date**: 2026-03-15  
**Status**: Resolved  
**Priority**: Medium  
**Reported By**: Customer  
**Related**: [2026-03-13-thoughts-html-entities-encoding.md](2026-03-13-thoughts-html-entities-encoding.md)

## Issue Description

Customer reported that thought content still displays with literal HTML entities, e.g.:

- `Daphne&#039;s breathing was 30 per minute. This is a good stable level for her.`

Expected: `Daphne's breathing was 30 per minute. This is a good stable level for her.`

## Investigation

The 2026-03-13 fix added `getDecodedContent()` and ensured all display paths (Blade, JSON, MCP, API) use decode-then-escape. Views and controllers were already using `getDecodedContent()` correctly.

Root cause: **content was still being stored with HTML entities** (e.g. from MCP `capture_plan`, API, or pasted/synced content). Display correctly decoded at read time, but in some contexts or after round-trips the literal entity string could still appear. More robust fix: **normalize on save** so the database holds plain text and every ingest path benefits.

## Root Cause Analysis

- Some sources (MCP, APIs, pasted content) send content that already contains HTML entities (`&#039;`, `&quot;`, etc.).
- The previous fix decoded only at **display** time. Stored content remained entity-encoded.
- Any code path that ever exposed raw `content` (or that decoded inconsistently) could still show entities. Normalizing at save ensures a single source of truth.

## Resolution

1. **Thought model**
   - Extracted **`Thought::decodeContentEntities(string): string`** (same loop as before) for reuse.
   - Added **`setContentAttribute` mutator** so whenever `content` is set (create/update), it is decoded before being stored. New and updated thoughts now store plain text (e.g. `Daphne's`).
   - **`getDecodedContent()`** now calls `decodeContentEntities($this->content)` so existing rows that already had encoded content in the DB still display correctly.

2. **Tests**
   - `ThoughtTest::test_content_is_normalized_on_save_html_entities_decoded` — creating a thought with `Daphne&#039;s` stores and returns `Daphne's`.
   - `ThoughtTest::test_decode_content_entities_handles_double_encoding` — double-encoded entities are decoded.

## Prevention & Follow-up

- **Existing data**: One-off Artisan command **`php artisan thoughts:normalize-content-entities`** decodes HTML entities in existing `thoughts.content`. Use `--dry-run` to see what would be updated, optionally `--limit=N` to cap how many thoughts are processed. Rows created before the mutator was added may still have entity-encoded content until this command is run; they continue to display correctly via `getDecodedContent()` either way.
- Ingest normalization is now the default; no additional display-surface checks required for new features.

## References

- Previous fix: [support/2026-03-13-thoughts-html-entities-encoding.md](2026-03-13-thoughts-html-entities-encoding.md)
- Model: `app/Models/Thought.php` (`decodeContentEntities`, `setContentAttribute`, `getDecodedContent`)
- Command: `app/Console/Commands/NormalizeThoughtContentEntitiesCommand.php` — `php artisan thoughts:normalize-content-entities [--dry-run] [--limit=N]`

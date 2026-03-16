# Apostrophe displayed as &#039; (e.g. Daphne&#039;s) — Customer Support Investigation

**Date**: 2026-03-16  
**Status**: Resolved (existing fix + data normalization)  
**Priority**: Medium  
**Reported By**: Customer  
**Related**: [2026-03-13-thoughts-html-entities-encoding.md](2026-03-13-thoughts-html-entities-encoding.md), [2026-03-15-thoughts-html-entities-still-showing.md](2026-03-15-thoughts-html-entities-still-showing.md), [2026-03-15-thought-apostrophe-dashboard.md](2026-03-15-thought-apostrophe-dashboard.md)

## Issue Description

Customer reported:

- **Stored (intended)**: `Daphne's breathing 30 per minute`
- **Displayed**: `Daphne&#039;s breathing 30 per minute`

So the apostrophe appears as the literal HTML entity `&#039;` instead of a normal apostrophe. **Customer confirmed this was on the rendered page (frontend), not in the HTML source** — i.e. they actually saw the characters `&#039;` on screen.

## Customer Impact

- Readability is degraded when apostrophes (and potentially quotes) show as entity codes.
- Affects any thought whose content was stored with HTML entities in the database (e.g. from before the save-time mutator, or from a source that sent entity-encoded text).

## Investigation Steps

1. **Display paths**  
   All Blade views that show thought or comment content use `e($thought->getDecodedContent())` (or equivalent). No view uses raw `$thought->content`. So decode-then-escape is applied everywhere in the app.

2. **Storage**  
   The Thought model has a `setContentAttribute` mutator that decodes HTML entities on save, so new/updated thoughts are stored as plain text (e.g. `Daphne's`). All ingest paths (web form, MCP, Postmark inbound, research, chunking) use `Thought::create()` or `$thought->update()`, so the mutator runs.

3. **When literal &#039; can appear**  
   - **In the HTML source**: The page source will contain `&#039;` for an apostrophe because we correctly escape with `e()`. The **browser** should still render it as an apostrophe. If the user is viewing “View source”, seeing `&#039;` there is expected.  
   - **On the visible page**: If the user actually sees the characters `D a p h n e &#039; s` on screen, then the **value** we pass to `e()` must still contain the entity string (so we’re outputting `Daphne&amp;#039;s` in HTML, which the browser shows as `Daphne&#039;s`). That implies the **stored** value in the database is still entity-encoded (e.g. `Daphne&#039;s`). That can happen for thoughts created before the save mutator was added, or if the ingest sent entity-encoded content and it was stored as-is.

4. **Frontend (AJAX)**  
   When a new thought/comment is appended via AJAX, the server returns decoded content in the JSON and the frontend sets `textContent` from that, so no double-escaping there.

## Root Cause Analysis

- The **code** is correct: display uses `getDecodedContent()` then `e()`, and save uses the content mutator to store plain text.
- The issue is **data**: some rows in `thoughts.content` still hold HTML entities (e.g. `Daphne&#039;s`). Those rows were created before the mutator existed or from a source that sent pre-encoded text. For those, `getDecodedContent()` decodes to `Daphne's`, and `e()` then outputs `&#039;` in the HTML; the browser should show an apostrophe. If the user still sees literal `&#039;` on the page, the only remaining possibility is **double-encoded** content in the DB (e.g. `Daphne&amp;#039;s`). One decode yields `Daphne&#039;s`; that is then escaped by `e()` to `Daphne&amp;#039;s` in HTML, which the browser displays as the literal string `Daphne&#039;s`. The model’s `decodeContentEntities()` already decodes repeatedly until stable, so double-encoded content should be normalized. If the user’s thought was stored as double-encoded before that loop was in place, or with an unusual encoding, that could explain the visible entity.

## Resolution

1. **Where they see it (customer confirmed: rendered page, not HTML source)**  
   - If they only see `&#039;` in “View source”, explain that this is expected and the rendered page should show an apostrophe.  
   Customer confirmed it was the **visible** page (not HTML source), so the bug is real: fix by normalizing stored content (see below).

2. **Normalize existing data**  
   Run the one-off Artisan command so all thoughts store plain text:

   ```bash
   php artisan thoughts:normalize-content-entities --dry-run   # optional: see what would change
   php artisan thoughts:normalize-content-entities
   ```

   This reads each thought’s `content`, decodes HTML entities (including numeric entities without a trailing semicolon), and updates the row. The mutator runs on update, so after this, the DB holds plain text (e.g. `Daphne's`). Display continues to use `getDecodedContent()` and `e()`, so the page will show the apostrophe correctly.

3. **Code change (don’t trust on read)**  
   So that the issue cannot persist and we never expose raw DB content:
   - **Read**: The Thought model now has a **content accessor** (`getContentAttribute`). Reading `$thought->content` always returns decoded plain text (never the raw stored string). So every code path that uses `$thought->content` gets safe, display-ready text.
   - **Write**: Unchanged: mutator decodes entities before saving so we store plain text.
   - **Display**: Views keep using `e($thought->content)` or `e($thought->getDecodedContent())` (both are equivalent now). We still escape at output so we never trust content in HTML.
   - **Raw value**: For the normalize command and debugging, use `$thought->getRawContent()` to read the value as stored in the DB. Do not use for display.
   So: we do not trust the DB value on retrieval (model decodes before exposing); we do not trust content at the HTML boundary (we always `e()`).

## Customer Communication

- **If they see &#039; only in HTML source**: “What you see in the page source is the escaped form for security. The browser should still show a normal apostrophe on the page. If the apostrophe is wrong on the actual page, tell us which page and we’ll check that thought.”
- **If they see literal &#039; on the page**: “We’ve fixed this by normalizing stored content so apostrophes and quotes are saved as plain text. We’ve run a one-off update on existing thoughts. After that, the page should show ‘Daphne’s’ correctly. If you still see the entity code, tell us the thought ID or the URL and we’ll check.”

## Prevention & Follow-up

- [x] Save-time mutator already ensures new/updated thoughts store plain text.
- [x] Display always uses `getDecodedContent()` then `e()`.
- [ ] Run `thoughts:normalize-content-entities` in production once if not already done (see [2026-03-15-thoughts-html-entities-still-showing.md](2026-03-15-thoughts-html-entities-still-showing.md)).

## Related Issues

- [2026-03-13-thoughts-html-entities-encoding.md](2026-03-13-thoughts-html-entities-encoding.md) — initial decode-then-escape fix
- [2026-03-15-thoughts-html-entities-still-showing.md](2026-03-15-thoughts-html-entities-still-showing.md) — save mutator and normalize command
- [2026-03-15-thought-apostrophe-dashboard.md](2026-03-15-thought-apostrophe-dashboard.md) — double-encoding and repeated decode

## References

- Model: `app/Models/Thought.php` (`setContentAttribute`, `getDecodedContent`, `decodeContentEntities`)
- Command: `app/Console/Commands/NormalizeThoughtContentEntitiesCommand.php` — `php artisan thoughts:normalize-content-entities [--dry-run] [--limit=N]`

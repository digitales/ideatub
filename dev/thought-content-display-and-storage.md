# Thought content: display and storage (don’t trust input)

**Summary**: We do not trust user input at storage or at display. Content is normalized on save and decoded on read; we always escape when outputting to HTML.

## Storage (save)

- **Mutator** (`setContentAttribute`): Decodes HTML entities before saving so the DB holds plain text (e.g. `Daphne's` not `Daphne&#039;s`). All ingest paths use `Thought::create()` / `$thought->update()`, so the mutator runs.
- **Do not** store pre-escaped or entity-encoded content. Storing plain text is the single source of truth.

## Retrieval (read)

- **Accessor** (`getContentAttribute`): Reading `$thought->content` always returns **decoded** plain text. The model never exposes the raw DB value by default. So any code that uses `$thought->content` gets display-ready text.
- **Raw value**: For migrations, the normalize command, or debugging, use `$thought->getRawContent()` to read the value as stored. Do not use for display or API responses.

## Display (HTML)

- **Escape once**: Use `{{ $thought->content }}` in Blade. Do **not** wrap in `e()` — Blade's `{{ }}` already escapes (it compiles to `e()`). Using `{{ e($thought->content) }}` double-escapes (e.g. apostrophe → &#039; → &amp;#039;) and makes the browser show literal entity text like `&#039;`.
- **AJAX/JSON**: Controllers return decoded content (`$thought->content` or `$thought->getDecodedContent()`). The frontend must use `textContent` (or escape) when inserting into the DOM; never use `innerHTML` with unescaped content.

## Commands

- **Normalize legacy data**: `php artisan thoughts:normalize-content-entities [--dry-run] [--limit=N]` decodes HTML entities in existing `thoughts.content` using `getRawContent()` and then updates so the mutator runs. Run once if rows were created before the mutator/accessor existed.

## References

- Model: `app/Models/Thought.php` (`decodeContentEntities`, `setContentAttribute`, `getContentAttribute`, `getRawContent`, `getDecodedContent`)
- Support: `support/2026-03-16-apostrophe-displayed-as-entity.md`

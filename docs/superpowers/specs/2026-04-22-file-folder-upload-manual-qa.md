# File / folder import — manual QA (2026-04-22)

Checklist for staging or pre-release. Run with `FEATURE_FILE_UPLOAD=true` and a normal (non–demo) account.

## Homepage – quick import

1. Open `/` (signed in). Confirm the capture box shows a **Files** row with paperclip and folder actions (`data-capture-import-toolbar` in the HTML).
2. Use **paperclip**; pick one or more `.md` / `.txt` files. Confirm the pre-import modal appears; accept and complete the flow.
3. Confirm redirect back to the index with a success flash and new thought(s) visible; content matches the file and provenance is from upload.
4. Repeat with more than five small files: expect validation (client or server) blocking more than five at once for quick import.

## Homepage – batch / folder

1. Use the **folder** action; select a small folder with a few markdown files. Set project name and dedupe option in the modal; confirm.
2. Confirm redirect to `/imports/{batch}` and that per-file status updates (polling and/or Reverb, if enabled).
3. When the batch finishes, confirm email/in-app notification behaviour if you have `email_on_import_completion` enabled.

## Demo mode

1. Enable demo mode for a session. Confirm the file import toolbar is **not** shown on the home capture box.
2. If you hit import endpoints directly in demo mode, expect **403** with a clear message about uploads being disabled.

## Research on imported ideas

1. On the Ideas page, use **Research** on an idea that came from a file import. First attempt should require confirmation; after confirming, research should start.
2. On a thought detail page for an **idea** with upload provenance, use **Run research for this idea**; confirm the same browser confirm step when applicable.

## Security spot checks

1. Try a path with `..` in a batch upload (e.g. tampered relative path): expect rejection.
2. Prune: run `php artisan imports:prune-expired-batches --days=1` in a test database only and confirm only batches with `updated_at` older than the window are removed.

## Browsers

1. **Chrome** (or Chromium): quick + folder.
2. **Safari** (if applicable): quick import; note folder picker may be limited — confirm no JS errors.
3. **Firefox**: quick + folder.

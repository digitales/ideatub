# File and folder upload — Phase 3 (HTTP, UI, polish) — session summary and completion plan

> This document **summarizes a Cursor session** (April 2026) that **finished the remaining upload work** after the core pipeline (tasks 1–20) was already in the codebase. It is a **retrospective plan**: what was done, what to verify, and where files live. For the full end-to-end implementation checklist, see `2026-04-22-file-folder-upload.md` and the design spec.

## Conversation summary (what the user asked for)

1. **Continue** all plan tasks **21–30** unless blocked (import HTTP layer, show/status, actions, demo guard, prune command, homepage paperclip + folder, pre-upload modal, imported badge + research confirm, full test pass + `DEPLOY.md` + manual QA).
2. **Later (this thread):** **Summarise the conversation and add to IdeaTub as a plan** — this file.

The session **implemented or wired** the remaining product-facing behaviour, tests, and docs, building on an in-progress handoff that had already started `ImportController` requests, `FinaliseImportBatch`, `DemoMode::enabled()` (not `isEnabled()`), and `resources/views/imports/show.blade.php`.

---

## Scope covered in this session (Tasks 21–30 — outcome)

| Area | What was delivered |
|------|------------------|
| **21–24** | `ImportController@quick` / `batch` / `show` / `status` / `cancel` / `retryFailed` / `destroyThoughts` — already present from handoff; minor cleanup (e.g. unused import removal after Pint). |
| **25** | `tests/Feature/Upload/ImportDemoModeGuardTest.php` — 403 on `quick` and `batch` when `DemoMode` is mocked enabled. |
| **26** | `app/Console/Commands/PruneExpiredImportBatchesCommand.php` + `Schedule::command('imports:prune-expired-batches')->dailyAt('03:00')` in `routes/console.php` (not `Kernel.php` in this app). `tests/Feature/Upload/PruneExpiredImportBatchesCommandTest.php`. |
| **27–28** | Homepage `resources/views/idea/index.blade.php`: `data-capture-import-toolbar`, paperclip + `webkitdirectory` inputs **outside** the main thought form; Alpine in `resources/js/app.js` (`triggerImportFilePick`, `onImportQuickPicked`, `confirmImport`, `fetch` + redirect). Pre-upload **modal** for both quick and batch. |
| **29** | **Research:** `IdeaController@research` now returns a **redirect + flash** for normal HTML when `provenance_ack` is missing on upload-provenance ideas; **JSON 409** kept for `expectsJson()` / Ajax. **Ideas list** and **thought detail** forms use `onsubmit` + `confirm` + hidden `provenance_ack`. **Badge:** `data-imported-badge` in `thought_detail_header.blade.php`; **“Run research for this idea”** in `thought_detail_actions_row.blade.php` (idea + editable). `tests/Feature/Ideas/ResearchConfirmUploadProvenanceTest` updated: first case uses `postJson()` for 409. |
| **30** | New `tests/Feature/Upload/*` (quick, batch `Bus::assertBatched`, show/status, homepage toolbar, imported badge, prune). `DEPLOY.md` subsection on file import. `docs/superpowers/specs/2026-04-22-file-folder-upload-manual-qa.md`. |
| **Docs** | `DEPLOY.md` — feature flag, queue, scheduler, PHP `upload_max_filesize` / `post_max_size` notes. |

## Key file references (quick navigation)

- Controllers: `app/Http/Controllers/ImportController.php`, `app/Http/Controllers/IdeaController.php` (research provenance branch).
- Requests: `app/Http/Requests/QuickImportRequest.php`, `app/Http/Requests/BatchImportRequest.php`.
- UI: `resources/views/idea/index.blade.php`, `resources/js/app.js` (`captureBox` import state + `confirmImport`).
- Thought: `resources/views/idea/partials/ideas_list.blade.php`, `thought_detail_header.blade.php`, `thought_detail_actions_row.blade.php`.
- Import progress: `resources/views/imports/show.blade.php`.
- Command: `app/Console/Commands/PruneExpiredImportBatchesCommand.php` — `routes/console.php` schedule.
- Tests: `tests/Feature/Upload/`, `tests/Feature/Ideas/ResearchConfirmUploadProvenanceTest.php`.

## Verification checklist (for implementers / CI)

- [ ] `php artisan test` (or at least `tests/Feature/Upload` and `ResearchConfirmUploadProvenanceTest`) with valid **Postgres** test DB (`DB_*` in `phpunit.xml` / env).
- [ ] Manual: homepage toolbar hidden when `FEATURE_FILE_UPLOAD` off; visible when on and not demo; demo returns 403 on `POST /imports/*`.
- [ ] Manual: follow `docs/superpowers/specs/2026-04-22-file-folder-upload-manual-qa.md`.

## Known blockers / notes from the session

- **Local DB:** One environment failed tests with `FATAL: role "root" does not exist` — set `DB_USERNAME` (and password) to match your Postgres role for `ideatub_test`, then re-run the suite.
- **Echo / Reverb** on `imports/show` — private channel + event names; verify in browser if real-time progress is required beyond polling.

## Links

- **Design spec:** [2026-04-22-file-folder-upload-design.md](../specs/2026-04-22-file-folder-upload-design.md)
- **Full task plan (tasks 1–20+):** [2026-04-22-file-folder-upload.md](2026-04-22-file-folder-upload.md)
- **Manual QA:** [2026-04-22-file-folder-upload-manual-qa.md](../specs/2026-04-22-file-folder-upload-manual-qa.md)
- **Deployment env notes:** [DEPLOY.md](../../../DEPLOY.md) (repository root)

---

*Generated as a plan artefact to capture the “summarise this conversation” request; treat checkboxes as optional follow-up, not a second full implementation spec.*

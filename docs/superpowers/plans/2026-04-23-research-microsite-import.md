# Research microsite import and viewing — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement strict multi-file `NN-… .md` import as a research microsite (one thought per page, ordered, link-rewritten), with matching in-app and public shared paged document UI using the same Markdown → HTML stack as today.

**Architecture:** Detect a microsite at batch creation time, persist `import_kind: microsite` in `import_batches.options` (column already exists), and process the batch in a **single** queued job that reads all staged files, pre-validates **fail-closed** on duplicate `content_sha256` for any file, creates root + child thoughts in sort order, and runs a **link rewriter** over each page’s UTF-8. Public `GET /r/{token}/p/{pagePathSegment}` and in-app `idea.research.show` with a `?page={segment}` (or `p/{segment}`) selector render a shared nav partial and optional HTML `href` rewrite for the share view so the same stored markdown works in both contexts. No new first-class `microsites` table in v1.

**Tech stack:** Laravel 12, PHPUnit, League CommonMark, existing `ThoughtCaptureService` / `Thought` model, `import_batches` / `import_batch_files`, Reverb/Laravel bus batches.

**Source spec:** `docs/superpowers/specs/2026-04-23-research-microsite-import-design.md`  
**Related:** `docs/superpowers/specs/2026-04-22-file-folder-upload-design.md`

---

## File map (create / change)

| Area | Create | Modify |
| --- | --- | --- |
| Detection + ordering | `app/Services/Import/MicrositeFilename.php` (pure helpers for pattern + sort key + `page_path_segment` from relative path), `app/Services/Import/MicrositeImportDetector.php` (boolean `shouldUseMicrosite` from paths + uploaded files) | `app/Http/Requests/BatchImportRequest.php` (optional shared rules) |
| Import control | `app/Services/Import/MicrositeImportService.php` | `app/Http/Controllers/ImportController.php` |
| Jobs | `app/Jobs/ProcessMicrositeImportBatch.php` | `app/Jobs/FinaliseImportBatch.php` (if counts need microsite copy — optional) |
| Link rewrite + warnings | `app/Services/Import/MicrositeMarkdownLinkRewriter.php` | `app/Services/Import/FileImportService.php` (or extract shared sanitise only) |
| URLs + HTML for share | `app/Support/Research/MicrositeShareUrlHelper.php` | `app/Http/Controllers/SharedResearchViewController.php` |
| Research UI | `resources/views/idea/partials/microsite_reader.blade.php` | `resources/views/idea/research_show.blade.php` |
| Shared UI | `resources/views/shared_research/readonly.blade.php` (branch layout) | same |
| Controller | — | `app/Http/Controllers/IdeaController.php` (`showResearch` + which thought is "current" + section ordering) |
| Model helpers | `app/Models/Thought.php` (small methods: `isMicrositeDocument`, `childThoughtsForMicrositeOrdered`) | — |
| Routes | — | `routes/web.php` |
| Tests | `tests/Unit/Services/Import/MicrositeFilenameTest.php` | new feature tests (see tasks) |
| Client copy | (if batch modal is JS-driven, find where confirm lives) | batch import view / JS for microsite one-liner + chunking hidden |

---

### Task 1: `MicrositeFilename` + unit tests

**Files:**

- Create: `app/Services/Import/MicrositeFilename.php`
- Create: `tests/Unit/Services/Import/MicrositeFilenameTest.php`

- [ ] **Step 1: Failing unit tests (PHPUnit).**

Add `tests/Unit/Services/Import/MicrositeFilenameTest.php`:

```php
<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\MicrositeFilename;
use PHPUnit\Framework\TestCase;

class MicrositeFilenameTest extends TestCase
{
    public function test_parses_sort_key_and_page_segment_from_basename(): void
    {
        $this->assertSame(0, MicrositeFilename::parseSortKeyFromBasename('00-summary'));
        $this->assertSame(2, MicrositeFilename::parseSortKeyFromBasename('2-foo'));
        $this->assertSame(10, MicrositeFilename::parseSortKeyFromBasename('10-bar'));
    }

    public function test_matcher_accepts_and_rejects_examples_from_spec(): void
    {
        $this->assertTrue(MicrositeFilename::isValidPageBasename('1-intro'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('00-summary'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('12_findings'));
        $this->assertTrue(MicrositeFilename::isValidPageBasename('2-foo'));
        $this->assertFalse(MicrositeFilename::isValidPageBasename('00'));
        $this->assertFalse(MicrositeFilename::isValidPageBasename('narrative'));
    }

    public function test_basename_collision_detection(): void
    {
        $a = MicrositeFilename::pagePathSegmentFromBasename('00-a');
        $b = MicrositeFilename::pagePathSegmentFromBasename('00-b');
        $this->assertFalse(MicrositeFilename::hasDuplicatePathSegments([$a, $b]));
        $this->assertTrue(MicrositeFilename::hasDuplicatePathSegments([$a, $a]));
    }
}
```

Adjust the duplicate test once the API names are final.

- [ ] **Step 2: Run to verify RED.**

```bash
cd /Users/rosstweedie/Sites/ideatub
php vendor/bin/phpunit tests/Unit/Services/Import/MicrositeFilenameTest.php
```

Expected: failures / errors for missing class and methods.

- [ ] **Step 3: Implement `MicrositeFilename` — minimal public API:**

```php
<?php

namespace App\Services\Import;

final class MicrositeFilename
{
    private const PATTERN = '/^(\d+)([-._])(.+)$/S';

    public static function isValidPageBasename(string $basenameNoExt): bool
    {
        if (preg_match(self::PATTERN, $basenameNoExt, $m) !== 1) {
            return false;
        }

        return $m[3] !== '' && $m[3] !== null;
    }

    public static function pagePathSegmentFromBasename(string $basenameNoExt): string
    {
        return $basenameNoExt; // spec: full basename, e.g. 00-summary
    }

    public static function parseSortKeyFromBasename(string $basenameNoExt): int
    {
        if (preg_match(self::PATTERN, $basenameNoExt, $m) === 1) {
            return (int) $m[1];
        }
        return PHP_INT_MAX;
    }

    /** @param  list<string>  $segments */
    public static function hasDuplicatePathSegments(array $segments): bool
    {
        return count($segments) !== count(array_unique($segments));
    }

    /**
     * @param  list<array{relative_path: string}>  $rows
     * @return list<array{relative_path: string, page_path_segment: string, sort_key: int, basename: string}>
     */
    public static function sortedSiteRowsFromRelativePaths(array $rows): array
    {
        // map basename from relative_path; filter must happen at caller; sort by sort_key then strcmp basename
    }
}
```

(Complete the body of `sortedSiteRowsFromRelativePaths` in the same file — implementation detail.)

- [ ] **Step 4: Run to verify GREEN.**

```bash
php vendor/bin/phpunit tests/Unit/Services/Import/MicrositeFilenameTest.php
```

Expected: all pass.

- [ ] **Step 5: Commit.**

```bash
git add app/Services/Import/MicrositeFilename.php tests/Unit/Services/Import/MicrositeFilenameTest.php
git commit -m "test(import): add microsite filename parsing helpers"
```

---

### Task 2: Batch import validation — `import_kind: microsite` in `options`

**Files:**

- Modify: `app/Http/Requests/BatchImportRequest.php`
- Modify: `app/Http/Controllers/ImportController.php`

- [ ] **Add private detection:** After path/file count rules, in `withValidator` `$validator->after` block:

  - If `count($files) < 2` → not microsite.
  - If **any** file is not `md` / `markdown` (by extension) → not microsite (or fail microsite: spec says **exhaustive** all-md with valid names — a `.txt` in the set makes the batch **not** a microsite; classic rules apply, including `mimes` which may need to allow microsite to require only `md` when all are `md` **or** require user to not mix — match spec: *if user includes any file that is not a matching `*.md`*, not microsite).
  - For every `relative_path`, take basename, strip extension; run `MicrositeFilename::isValidPageBasename`.
  - If all valid and at least 2 and all extensions are `md` → set `$this->merge(['import_kind' => 'microsite'])` (or read-only attribute on the request) — in Laravel, use a **normalised** property via `import_kind` in validated input. Prefer: **computed in controller** from the same `MicrositeImportDetector::analyse($paths, $files)`.

**Simpler approach:** do **not** add `import_kind` to the HTTP body from the client; set it only in `ImportController` after `BatchImportRequest` passes, using a dedicated static method e.g. `MicrositeImportDetector::shouldUseMicrosite($paths, $files): bool`.

- [ ] **Failing test** in `tests/Feature/Import/MicrositeImportDetectionTest.php` (or extend `BatchImportDispatchTest`):

  - `00-a.md` + `01-b.md` only, valid paths → microsite.
  - Add `notes.txt` in files → not microsite.
  - `00-a.md` alone (batch with one file) → not microsite (same request structure as existing tests).

- [ ] **Run:** `php artisan test tests/Feature/Import/ --filter=MicrositeImportDetection`

- [ ] **Commit** when green.

---

### Task 3: `MicrositeImportService` — dedupe, thoughts, rewrites, batch rows

**Files:**

- Create: `app/Services/Import/MicrositeImportService.php`
- Create: `app/Services/Import/MicrositeMarkdownLinkRewriter.php` (or fold into one service; split for tests)
- Create: `app/Jobs/ProcessMicrositeImportBatch.php`
- Modify: `app/Http/Controllers/ImportController.php` — in `batch()`, if `MicrositeImportDetector` true: save batch with `'options' => array_merge($batch->options ?? [], ['import_kind' => 'microsite'])` (or replace), stage files as now, then **dispatch** `ProcessMicrositeImportBatch::dispatch($batch->id)` inside `Bus::batch([...])` with **one** job, and `->finally` still calling `FinaliseImportBatch` (same as today). **If not microsite, keep the existing** `array_map` of `ProcessImportFile`.

- [ ] **Deduplication (fail closed):** Before any insert, for each file read staged bytes, sanitise as `FileImportService::sanitiseBytes` does, compute `sha256`. If **any** SHA **already** exists for `user_id` in `thoughts`, **set every** `import_batch_file` in this batch to failed with a shared `error_code` (e.g. `microsite_duplicate`, message `Microsite import requires all files to be new.`), and **return** (no partial thoughts). Implementation must not link duplicates into the project for microsite.

- [ ] **Create thoughts:** `ThoughtCaptureService` with `no_chunking: true`, `doc_type: research`, a shared `plan_slug` and `project` in metadata for all pages, `source` `upload` / `upload_folder` per existing `ImportController` logic. First sorted page: `parent_id: null` + set `source_metadata` including `document_layout: microsite`, `page_path_segment`, `import_order`, provenance, batch id, file_path. **Subsequent** pages: `parent_id: root->id`, same. Apply `metadata.type` the same way other research imports do (reuse `applyDocTypeToMetadata` by passing `idea_metadata` or existing capture parameters — mirror `FileImportService::captureThought`).

- [ ] **Link rewrite:** For each file’s `clean` string, call `MicrositeMarkdownLinkRewriter` with:

  - Map: `path_segment` → (reserved for future thought id, not required if href uses only `/research/{rootId}?page=...`).
  - Root `Thought` `id` after root is created; children after.

  **In-app URL shape (v1, fixed):**  
  - Root page: `/research/{rootId}` (no query)  
  - Any page, including a child, resolved by **one** of: `GET /research/{rootId}?page={urlencoded segment}` **or** `GET /research/{rootId}/p/{segment}` — **pick a single** route in implementation. The plan recommends **`Route::get('/research/{thought}/p/{page}', ...)->where('page', '[0-9A-Za-z._-]+')` named e.g. `idea.research.page`** so the segment does not need URL encoding. Default `showResearch` for microsite can redirect to `route('idea.research.page', ['thought' => $root, 'page' => '00-summary'])` if no page given.

- [ ] **Rewriter implementation sketch:** walk markdown links in CommonMark, or use regex with tests for `[]()` and reference-style. Only rewrite `*.md` where resolved target is one of the batch’s basenames. Never emit `javascript:`. Target href: `route('idea.research.page', [root, segment])` **or** if rendering root-only route: same.

- [ ] **Local asset warning:** one pass counts `![]\(` and `![][ref]` — store count on `import_batches.options` key `local_asset_ref_count` for display on `imports.show` if we want, or a flash — spec says *batch summary* warning. Minimum: log to `import_batch` completion email / inbox? Spec says *post-import* warning: implement as `ImportBatch` option string `import_warnings: ['local_asset_references' => 3]`.

- [ ] **Test:** `tests/Feature/Import/MicrositeImportCreatesThoughtsTest.php` with fake queue or `Bus::fake()` — end-to-end happy path, duplicate SHA fails whole batch, link rewrite expectation on stored `content` substring.

- [ ] **Commit** in logical chunks (service + job + test).

---

### Task 4: `Thought` + `IdeaController` — paged in-app research

**Files:**

- Modify: `app/Models/Thought.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `routes/web.php`
- Create: `resources/views/idea/partials/microsite_reader.blade.php`
- Modify: `resources/views/idea/research_show.blade.php`

- [ ] **Add** `Thought::isMicrositeRoot(): bool` — e.g. `data_get($this->source_metadata, 'document_layout') === 'microsite' && $this->parent_id === null`.

- [ ] **Add** `Thought::childThoughtsForMicrosite()` — `return $this->childThoughts()->orderBy('source_metadata->import_order', 'asc')` (verify JSON column / DB driver in tests — SQLite in tests may need `orderByRaw`).

- [ ] **In `showResearch`:** if microsite root: resolve `$currentPage` from `page` route param or `Request::route('page')` / query. If user hits a **child** `Thought` for microsite, redirect to canonical `idea.research.page` with the child’s `page_path_segment` (or root + segment). **If** a non-microsite child is visited (old behaviour), still redirect to parent, but for microsite children, redirect to `?page=…` or `/p/…` instead of the single-scroll view.

- [ ] **View:** if microsite, pass `micrositeNavItems` (collection: segment, label, `thought` id) and `currentContentHtml` + `currentThought` for the selected page. **Prose** partial reuse from existing research partial.

- [ ] **Route:** register the new route **before** the generic `research/{thought}` if Laravel would otherwise not match. Name it `idea.research.page` if using `/research/{thought}/p/{page}`.

- [ ] **Feature test:** `ResearchShowTest` or new: microsite document shows nav + correct HTML for `03-x` when requested.

- [ ] **Commit.**

---

### Task 5: Public shared paged read — `SharedResearchViewController` + HTML href rewrite

**Files:**

- Create: `app/Support/Research/MicrositeShareUrlHelper.php` (or inline private methods)
- Modify: `app/Http/Controllers/SharedResearchViewController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/shared_research/readonly.blade.php` (or new partial for microsite)
- Create: `tests/Feature/SharedMicrositeViewTest.php`

- [ ] **Add route** `GET /r/{token}/p/{page}` (GET only; POST stays password) named e.g. `shared-research.page`. Use same `show` controller with optional parameter or a dedicated `showPage` that shares password gate logic. **Refactor** password gate into a protected method that accepts a closure `render(ResearchShare, ?string $pageSegment)`.

- [ ] **For microsite root in `renderReadonly`:** if not microsite, keep current stacked layout. If microsite: load root + `childThoughtsForMicrosite`, determine current by optional `$page` segment; default to root. Render same layout partial as in-app (extract shared **Blade component** to `resources/views/components/microsite_reader.blade.php` to avoid copy-paste).

- [ ] **Href rewrite:** if stored markdown `href` values point to in-app `route('idea.research.page', …)`, replace the host-relative path in **HTML** output of each block with the shared equivalent:

  - `/r/{token}` and `/r/{token}/p/{page}` (token from share) — add tests that **password cookie** is required for `showPage` same as `show`.

- [ ] **Run:** `php artisan test tests/Feature/SharedMicrositeViewTest.php`

- [ ] **Commit.**

---

### Task 6: Inbox, email, and import show copy

**Files:**

- Modify: `app/Jobs/FinaliseImportBatch.php` (or the notifier that runs after batch)
- Modify: `resources/views/imports/show.blade.php` (or equivalent)
- Modify: mail views if copy references “N thoughts”

- [ ] When `options.import_kind === 'microsite'` and batch completes, prefer copy *“Research site: N pages”* (spec §7) in notifications / mail subject body.

- [ ] `imports.show` “go to” link: open `idea.research.show` (or `idea.research.page` default) of the **root** thought (store `root_thought_id` on `import_batches` **only** if we need it, or first done file with min sort key — if none, add optional `import_batches.options.root_thought_id` set in job).

- [ ] **Commit.**

---

### Task 7: Client: batch import modal and chunking / microsite copy

**Files:** locate pre-upload view and JS in `resources/` (grep for `import` / `no_chunking` / `data-capture-import`).

- [ ] When the batch will be `microsite` (detected client-side mirroring the server, or by showing message after file pick): show one-sentence spec copy; hide or disable “Split at headings” for that batch. **The server** is the source of truth; client is advisory only.

- [ ] **Commit.**

---

### Task 8: Backfill / compatibility

- [ ] Existing `research` roots with `document_layout` absent remain **unchanged** (section-scroll layout). Only microsite-tagged documents use the new view.

- [ ] **Regress** `php artisan test` and targeted suites.

- [ ] **Commit** as needed.

---

## Plan self-review (spec coverage)

| Spec section | Plan tasks |
| --- | --- |
| §2 strict detection, ≥2 files, all md, basename pattern | Task 1–2 |
| §3 order + min root as parent, children | Task 3 |
| §4 metadata, fail-closed dedupe | Task 3, 6 |
| §5 link rewrite, in-app + share | Task 3–5 |
| §6 UI, comments per thought | Task 4–5 |
| §7 batch: chunking N/A, import page link, notifications | Task 6–7 |
| §8–10 edge cases, security, tests | Tasks 1–2, 3–5, 8 |

**Placeholder check:** This plan does not use “TBD”; route names in Task 4–5 must be chosen once in code and then mirrored in this doc if a global rename is needed.

**Type / naming:** `import_kind` in `import_batches.options` and `source_metadata.document_layout` use distinct keys — the plan reuses the spec’s `document_layout: microsite` and adds `import_kind: microsite` for batch control.

---

## Plan complete

Plan saved to `docs/superpowers/plans/2026-04-23-research-microsite-import.md`.

**Execution options:**

1. **Subagent-driven (recommended):** A fresh subagent per task, review between tasks, fast iteration. Use the **subagent-driven-development** sub-skill.  
2. **Inline:** Execute in this session using the **executing-plans** sub-skill, batching steps with checkpoints.

**Which approach do you want?**

If none is chosen, start with **Task 1** in a dedicated worktree when the repo is mid-flight with other work.

---

## Git

After you finish editing this plan, commit with:

```bash
git add docs/superpowers/plans/2026-04-23-research-microsite-import.md
git commit -m "docs(plans): add research microsite import implementation plan"
```

(Agent should run this for the user.)

---

_End of plan._

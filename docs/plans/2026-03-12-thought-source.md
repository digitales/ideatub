# Thought source — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add `source` and `source_metadata` to thoughts so we can record origin (web, MCP, or client name like ChatGPT/Claude/Cursor) and leave room for future calendar metadata.

**Architecture:** New nullable columns on `thoughts`; web always sets `source = 'web'`; MCP accepts optional `source` / `source_metadata` and defaults to `mcp`; MCP list responses and web UI expose source; UI shows a small label when source is non-null (nothing when null).

**Tech Stack:** Laravel 12, Pest, existing Thought model and MCP/IdeaController.

**Design reference:** [docs/plans/2026-03-12-thought-source-design.md](2026-03-12-thought-source-design.md)

---

## Task 1: Migration and Thought model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_source_to_thoughts_table.php`
- Modify: `app/Models/Thought.php`

**Step 1: Create migration**

Run: `php artisan make:migration add_source_to_thoughts_table --table=thoughts`

Edit the new migration in `database/migrations/`. In `up()`:

```php
$table->string('source', 64)->nullable()->index()->after('user_id');
$table->json('source_metadata')->nullable()->after('source');
```

In `down()`:

```php
$table->dropColumn(['source', 'source_metadata']);
```

**Step 2: Run migration**

Run: `php artisan migrate`

Expected: Migration runs successfully.

**Step 3: Update Thought model**

In `app/Models/Thought.php`:
- Add `'source'` and `'source_metadata'` to `$fillable` (e.g. after `'parent_id'`).
- In `casts()`, add: `'source_metadata' => 'array'`.

**Step 4: Commit**

```bash
git add database/migrations/*_add_source_to_thoughts_table.php app/Models/Thought.php
git commit -m "feat: add source and source_metadata to thoughts"
```

---

## Task 2: Web — set source when creating thought

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`

**Step 1: Set source in payload**

In `IdeaController::store()`, in the `$payload` array passed to `Thought::create()`, add:

```php
'source' => 'web',
'source_metadata' => null,
```

(Place after `'user_id'` or with the rest of the payload.)

**Step 2: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "feat(web): set source=web when creating thought from web"
```

---

## Task 3: MCP — capture_thought accepts source and source_metadata

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`

**Step 1: Validate and normalize source params**

In `captureThought()`, extend the validator to include:

```php
'source' => 'sometimes|nullable|string|max:64',
'source_metadata' => 'sometimes|nullable|array',
```

After validation, before building `$payload`, compute:

- `$source = isset($params['source']) && trim((string) $params['source']) !== '' ? mb_substr(trim((string) $params['source']), 0, 64) : 'mcp';`
- `$sourceMetadata = isset($params['source_metadata']) && is_array($params['source_metadata']) ? $params['source_metadata'] : null;`

**Step 2: Add to payload and tool schema**

In the `$payload` passed to `Thought::create()`, add:

```php
'source' => $source,
'source_metadata' => $sourceMetadata,
```

In `respondToolsList()` (or wherever `capture_thought` inputSchema is defined), add to the tool’s `properties`:

```php
'source' => ['type' => 'string', 'description' => 'Optional source label (e.g. chatgpt, claude, cursor)'],
'source_metadata' => ['type' => 'object', 'description' => 'Optional source-specific metadata'],
```

**Step 3: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php
git commit -m "feat(mcp): capture_thought accepts optional source and source_metadata"
```

---

## Task 4: MCP — include source and source_metadata in list responses

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`

**Step 1: Include columns in queries**

In `searchThoughts()`, change `->get(['id', 'content', 'metadata', 'created_at'])` to include `'source'`, `'source_metadata'`. In the `map` over thoughts, add to each item:

```php
'source' => $t->source,
'source_metadata' => $t->source_metadata,
```

In `browseRecent()`, make the same change: add `source` and `source_metadata` to `get([...])` and to the mapped array.

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php
git commit -m "feat(mcp): include source and source_metadata in search_thoughts and browse_recent"
```

---

## Task 5: UI — show source label (nothing if null)

**Files:**
- Modify: `resources/views/idea/index.blade.php`

**Step 1: Add source label in thought card**

In the thought card, in the flex row that shows `created_at` and tags (around line 151), add after the `diffForHumans()` span and before the tags loop:

```blade
@if ($thought->source)
    <span class="text-[10.5px] text-slate-brand/40">{{ ucfirst(strtolower($thought->source)) }}</span>
@endif
```

So the order is: time, then source (if present), then tags, then Reply link. Use existing styling (e.g. `text-[10.5px] text-slate-brand/40`) so it matches the timestamp.

**Step 2: Commit**

```bash
git add resources/views/idea/index.blade.php
git commit -m "feat(ui): show thought source label when present"
```

---

## Task 6: Tests

**Files:**
- Modify: `tests/Feature/McpApiTest.php`
- Create or modify: `tests/Feature/IdeaPageTest.php` (or existing web thought test)

**Step 1: Test web sets source**

In a test that creates a thought via the web (e.g. IdeaPageTest or new test), after submitting the capture form, assert the created thought has `source === 'web'`. If there is no such test, add a minimal one: log in, POST to thoughts.store with content, then assert Thought::latest()->first()->source === 'web'.

**Step 2: Test MCP default and override**

In `tests/Feature/McpApiTest.php`:

- Add a test that calls `capture_thought` via POST to `/api/mcp?key=...` with only `content`; then assert the created thought has `source === 'mcp'`.
- Add a test that calls `capture_thought` with `content` and `source` => `'claude'`; assert the thought has `source === 'claude'`.
- Optionally: test that `browse_recent` or `search_thoughts` response includes `source` and `source_metadata` for a thought.

**Step 3: Run tests**

Run: `php artisan test` (or `./vendor/bin/pest`)

Expected: All tests pass.

**Step 4: Commit**

```bash
git add tests/
git commit -m "test: thought source web and mcp"
```

---

## Task 7: Docs

**Files:**
- Modify: `docs/mcp-integration-guide.md` or `docs/mcp-capture-thought.md` (or equivalent MCP doc if path differs)

**Step 1: Document capture_thought params**

In the section that describes `capture_thought`, add:

- Optional `source` (string): client or origin label (e.g. `chatgpt`, `claude`, `cursor`). If omitted, stored as `mcp`.
- Optional `source_metadata` (object): arbitrary key-value metadata for the source (e.g. for future calendar: event_id).

Note that `search_thoughts` and `browse_recent` responses now include `source` and `source_metadata` per thought.

**Step 2: Commit**

```bash
git add docs/
git commit -m "docs: document thought source and source_metadata for MCP"
```

---

## Execution options

Plan complete and saved to `docs/plans/2026-03-12-thought-source.md`.

**Two execution options:**

1. **Subagent-driven (this session)** — I run each task (or dispatch a subagent per task), you review between tasks; fast iteration.
2. **Parallel session (separate)** — You open a new session (e.g. in a worktree), use **executing-plans** there, and run through the plan with checkpoints.

Which approach do you want?

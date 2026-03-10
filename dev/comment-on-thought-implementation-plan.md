# Comment-on-thought — Implementation Plan

**Date**: 2025-03-10  
**Status**: Draft (not started)  
**Source**: [decisions/2025-03-10-comment-on-thought-future-work.md](../decisions/2025-03-10-comment-on-thought-future-work.md)

This plan breaks the “comment-on-thought / follow-up comments” feature into implementable chunks. Execute in order; later chunks depend on the data model and backend from Chunk 1–2.

**Prerequisite:** Confirm Evernote behaviour for comments (see [§Evernote product decision](#evernote-product-decision) and Chunk 5). Default assumption for this plan: **(B) Append comments to parent note** unless product chooses (A).

---

## Evernote product decision

Before implementing Chunk 5, decide:

- **(A) One Evernote note per comment** — Comment thoughts sync as separate notes (e.g. same notebook as parent). `SyncThoughtToEvernote` already creates a note; ensure parent’s note exists first if ordering matters.
- **(B) Append to parent note** — Comments do not create new Evernote notes; when a comment is saved, update the parent thought’s Evernote note by appending the comment content. New job or extended `SyncThoughtToEvernote` logic.

This plan spells out implementation for **(B)** in Chunk 5; if **(A)** is chosen, simplify Chunk 5 to “sync comment as new note (same pipeline as top-level thought)” and ensure notebook resolution uses parent’s notebook or a comments notebook.

---

## Chunk 1: Data model — `parent_id` on thoughts

**Goal:** Thoughts can optionally have a parent; comments are thoughts with `parent_id` set. Same table, one pipeline.

### Task 1.1: Migration and Thought model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_parent_id_to_thoughts_table.php`
- Modify: `app/Models/Thought.php`

- [ ] **Step 1:** Migration: add `parent_id` nullable UUID to `thoughts`; foreign key to `thoughts.id`; index on `parent_id`; index on `(user_id, parent_id)` or composite for “top-level only” (`parent_id IS NULL`) queries.
- [ ] **Step 2:** Run migration: `php artisan migrate`
- [ ] **Step 3:** Thought model: add `parent_id` to `fillable`; add `parent()` → `belongsTo(Thought::class, 'parent_id')`; add `children()` or `comments()` → `hasMany(Thought::class, 'parent_id')`. Add scopes: `topLevel()` (whereNull('parent_id')), `repliesTo(Thought $thought)` (where('parent_id', $thought->id)).
- [ ] **Step 4:** Ensure `Thought::create` does not allow setting `parent_id` to a thought owned by another user (validation in controller/service; policy in Chunk 2).
- [ ] **Step 5: Commit**  
  `feat: add parent_id to thoughts for comment-on-thought`

---

## Chunk 2: Backend — Authorization and validation

**Goal:** Only the owner of a thought can add a comment to it; parent must exist and belong to the current user.

### Task 2.1: Policy and validation

**Files:**
- Create or modify: `app/Policies/ThoughtPolicy.php`
- Modify: `app/Http/Controllers/IdeaController.php` (or service used by store)
- Modify: `app/Http/Controllers/Api/McpController.php` (capture_thought)

- [ ] **Step 1:** ThoughtPolicy: add `comment(User $user, Thought $thought): bool` — allow if `$thought->user_id === $user->id`. Optionally reuse or add `view` for “can see this thought (and thus comment on it)”.
- [ ] **Step 2:** Register policy for Thought in `AppServiceProvider` or `AuthServiceProvider` if not already.
- [ ] **Step 3:** Web store: when `parent_id` is present in request, load parent Thought; authorize `comment` (or equivalent); set `parent_id` on new thought and ensure parent’s `user_id` equals `auth()->id()`.
- [ ] **Step 4:** MCP `capture_thought`: when `parent_id` (or `in_reply_to`) is provided, load parent; verify parent belongs to resolved user; set `parent_id` on new thought. Return 4xx or tool error if parent not found or not owned by user.
- [ ] **Step 5: Commit**  
  `feat: authorize comment-on-thought (web + MCP)`

---

## Chunk 3: Web GUI — Capture and display

**Goal:** User can add a comment from a thought in search results or recent list; thoughts can display their comments below.

### Task 3.1: Capture comment (form + route)

**Files:**
- Modify: `resources/views/idea/index.blade.php` (or partials)
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `routes/web.php` (if new route needed)

- [ ] **Step 1:** Add “Comment” (or “Reply”) action to each thought in search results and recent list (e.g. button or link). Action targets same `POST /thoughts` with hidden or query param `parent_id` (thought UUID), or dedicated route e.g. `POST /thoughts/{thought}/comments` that resolves to same store logic with parent set.
- [ ] **Step 2:** Capture form: when adding a comment, show context (e.g. “Replying to: <snippet>”) and pass `parent_id`. Controller validates parent and authorizes `comment`; creates thought with `parent_id` and same embed + metadata pipeline.
- [ ] **Step 3:** After successful post, redirect or refresh so the new comment appears (e.g. back to index or to expanded parent).
- [ ] **Step 4: Commit**  
  `feat: web UI add comment on thought`

### Task 3.2: Display comments under a thought

**Files:**
- Modify: `resources/views/idea/index.blade.php` (or thought partial/card)

- [ ] **Step 1:** When rendering a thought, optionally load `$thought->children()` (or `comments()`). Display them below the thought (inline or expandable). Order by `created_at`.
- [ ] **Step 2:** Decide “recent” list behaviour: show only top-level thoughts (`parent_id IS NULL`) or mixed timeline. Document in plan or spec; implement filter in IdeaController index (e.g. `Thought::topLevel()->...` for recent).
- [ ] **Step 3: Commit**  
  `feat: display comment threads in idea index`

---

## Chunk 4: MCP — capture_thought and tool responses

**Goal:** MCP can create a comment via optional `parent_id` / `in_reply_to`; search/browse can return `parent_id` for grouping.

### Task 4.1: capture_thought optional parent_id

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php` (or MCP tool handler)

- [ ] **Step 1:** In `capture_thought` tool, accept optional parameter `parent_id` (UUID string) or `in_reply_to` (alias). If present: load Thought by id; verify `thought->user_id === resolved user`; set `parent_id` on new thought. If parent not found or wrong user, return tool error.
- [ ] **Step 2:** Document in `docs/` or README that MCP can pass `parent_id` to attach a new thought as a comment.
- [ ] **Step 3: Commit**  
  `feat: MCP capture_thought optional parent_id`

### Task 4.2: search_thoughts and browse_recent return parent_id

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php` (tool response shape)

- [ ] **Step 1:** Ensure thought payloads in `search_thoughts` and `browse_recent` include `parent_id` (nullable) when present. No breaking change if clients ignore it; clients can group by parent for thread display.
- [ ] **Step 2:** Optional: add tool parameter to filter “top-level only” (e.g. `top_level_only: true`) if product wants it. Implement by scoping to `Thought::topLevel()` when flag set.
- [ ] **Step 3: Commit**  
  `feat: MCP search/browse include parent_id in thought payloads`

---

## Chunk 5: Evernote mirror — comment sync behaviour

**Goal:** Implement chosen Evernote behaviour for comments (this plan assumes **B: append to parent note**).

### Task 5.1: Append comment to parent note (option B)

**Files:**
- Modify: `app/Services/EvernoteService.php`
- Modify: `app/Jobs/SyncThoughtToEvernote.php` (or new job)

- [ ] **Step 1:** When syncing a thought that has `parent_id`: do **not** create a new Evernote note. Instead, load parent thought; if parent has `evernote_note_guid`, call EvernoteService to append content to that note (e.g. new method `appendToNote(string $noteGuid, string $content)` or `updateNoteAppendContent`). If parent has no `evernote_note_guid`, optionally create parent note first then append, or skip comment sync and log.
- [ ] **Step 2:** In SyncThoughtToEvernote job: if `$thought->parent_id` is set, dispatch or run “append to parent note” path instead of “create/update own note” path. Ensure idempotency (e.g. append is additive; avoid duplicate appends if job retries — consider storing sync state or appending with a delimiter/timestamp).
- [ ] **Step 3:** If product chose **(A) one note per comment**, skip Task 5.1 and instead ensure SyncThoughtToEvernote creates a note for the comment thought as usual; notebook resolution can use parent’s notebook or config for “comments” notebook.
- [ ] **Step 4: Commit**  
  `feat: Evernote append comment to parent note (option B)`

### Task 5.2: Document Evernote decision

**Files:**
- Modify: `decisions/project-spec.md` or `decisions/2025-03-10-comment-on-thought-future-work.md`

- [ ] **Step 1:** Add one paragraph stating chosen behaviour: “Comments are synced to Evernote by appending to the parent thought’s note (option B)” or “Comments are synced as separate notes in the same notebook as parent (option A).”
- [ ] **Step 2: Commit**  
  `docs: Evernote comment sync behaviour`

---

## Chunk 6: Search and retrieval behaviour

**Goal:** Semantic search includes comments by default (so “find that comment” works); optional top-level-only filter; search results can show parent context.

### Task 6.1: Search scope and optional filter

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (web search)
- Modify: `app/Http/Controllers/Api/McpController.php` (search_thoughts) if not done in Chunk 4

- [ ] **Step 1:** Web: semantic search continues to run over all thoughts (top-level + comments). No change to query unless product asks for “top-level only” filter (e.g. `?q=...&top_level=1`).
- [ ] **Step 2:** When returning a thought that has `parent_id`, optionally load parent and include snippet in view (e.g. “Comment on: …”) for display. IdeaController passes thought with `parent` loaded when needed.
- [ ] **Step 3: Commit**  
  `feat: search includes comments; optional parent context in results`

---

## Summary checklist

| Chunk | Description |
|-------|-------------|
| 1 | Migration `parent_id`; Thought relationships and scopes |
| 2 | ThoughtPolicy comment; web + MCP validate parent ownership |
| 3 | Web: comment form + display threads |
| 4 | MCP: capture_thought parent_id; search/browse return parent_id |
| 5 | Evernote: append comment to parent note (or one-note-per-comment) + document |
| 6 | Search includes comments; optional parent context in results |

**References**

- [Comment-on-thought future work (decision)](../decisions/2025-03-10-comment-on-thought-future-work.md)
- [Project spec §4.1](../decisions/project-spec.md)

# Chunk thoughts on save — Plan & recommendations

**Date:** 2026-03-14  
**Status:** Plan (recommendations)  
**Goal:** When adding a thought (especially a long document), split it into smaller entries by section, link the chunks for display, and apply the same tags to all chunks. Ideally support submitting one large document and having IdeaTub chunk it on save.

---

## Desired behaviour (summary)

1. **Split at titles** — When adding a thought, split it into smaller entries at markdown (or similar) section headings (`##`, `###`, etc.), so each stored unit is “one thought or one section”.
2. **Link chunks for display** — The split parts are linked together (e.g. parent + children) so Stream (and other views) can show them as one logical document.
3. **Tags apply to all chunks** — Any tags on the “main” thought (or provided at save time) apply to every chunk, so filtering by tag shows the full document.
4. **Single submission, server-side chunking** — Ideally the user (or MCP client) can submit one large research document and IdeaTub chunks it on save, instead of the client having to split and call the API multiple times.

---

## Current state

### How thoughts and chunks work today

- **Thought model:** One row per thought; `parent_id` links a thought to a parent (used for comments and for “document root → sections”).
- **Tags:** Stored in `metadata->tags`; Stream can filter by tag. Tag filter already includes top-level thoughts that have the tag **or** that have any child with the tag (so a document root appears when only section children are tagged).
- **Stream display:** For a thought with `comments` (children), Stream shows the root and nested section content. When viewing by tag, section content is shown in full (not truncated).
- **capture_plan (MCP):** Accepts one `content` string per call and creates one thought. Supports `parent_id`, `section_title`, `plan_slug`, `doc_type`, `tags`, `file_path`, `project`. Same tag (e.g. `decision:slug`) is applied when the client passes the same `plan_slug` and optional `tags` on every call.

### Where chunking happens today

- **Client-side only.** The Cursor/Claude instructions (e.g. `CLAUDE.md`, `.cursor/rules/ideatub-sync-docs.mdc`) tell the agent to:
  - Split the document at markdown section headings.
  - Call `capture_plan` once per section, in order.
  - Use the same `file_path`, `doc_type`, `plan_slug`, and `project` for every section (so all get the same doc tag).
  - Optionally create a root thought first and pass its UUID as `parent_id` for section thoughts so they’re linked.

So: **linking and tags already work** when the client splits and calls multiple times. The gap is **server-side chunking** — there is no way to send one large document in a single request and have IdeaTub split it and create N linked thoughts with shared tags.

---

## Other methods for saving thoughts (entry points)

| Entry point | Source | Chunking | Opt-out |
|-------------|--------|----------|--------|
| **Web form** | `IdeaController::store()` | ✅ Yes | Paste in capture box; >500 words → split at headings; optional “Don’t split” checkbox. |
| **MCP capture_plan** | `McpController::capturePlan()` | ✅ Yes | >500 words → split; `no_chunking` / `no-chunking` to opt out. |
| **MCP capture_thought** | `McpController::captureThought()` | ✅ Yes | Params `no_chunking` / `no-chunking` |
| **REST API** | `ThoughtsApiController::store()` (POST /api/thoughts) | ✅ Yes | Body `no_chunking` (boolean) |
| **Inbound email** | `PostmarkInboundService::process()` | ✅ Yes | Subject or body contains `[no-chunk]` or `[no_chunking]` (case-insensitive) |

All use **ThoughtCaptureService::create()**. Chunking is not in the Thought model; the service is the single place for create-one vs create-chunked.

---

## Recommendations

### 1. Add server-side “chunk on save” for documents (high value)

**Recommendation:** Add a way to submit one document and have IdeaTub split it at headings and create multiple thoughts, linked and tagged.

- **Options:**
  - **A. New MCP tool** (e.g. `capture_document`) that accepts full document content + options (doc_type, plan_slug, tags, project, file_path). Server splits at `##` / `###` (and optionally `#`), creates:
    - Optionally a single “root” thought (e.g. title or first paragraph) with the same tags.
    - One thought per section, each with the same tags and `source_metadata` (doc_type, plan_slug, file_path, project, section_title), and `parent_id` set to the root (if present) for linking.
  - **B. Extend `capture_plan`** with a parameter such as `auto_chunk: true`. When true, `content` is treated as a full document: server splits at headings, then behaves like A (create root if desired + one thought per section, same tags and metadata, parent_id for sections).

- **Benefits:** Single paste or single MCP call for long research docs; no dependency on the client doing the split; consistent splitting rules and ordering (e.g. by creation order).

- **Split rules (suggested):**
  - Split on lines that are markdown headings: `# `, `## `, `### `, etc. (at start of line, optional leading whitespace).
  - Content before the first heading = first section (can be stored as “Intro” or doc title).
  - Each chunk = heading line + everything until the next heading or EOF. Section title for `source_metadata.section_title` = heading text stripped of `#` and trimmed.

### 2. Keep client-side section-by-section flow

- **Recommendation:** Keep the existing `capture_plan` (one thought per call) as the primary way for agents that want to control section boundaries or send pre-split sections. Server-side chunking is an alternative for “dump full doc and let IdeaTub handle it.”

### 3. Linking and tags (already aligned)

- **Linking:** Use `parent_id`: create one root thought, then create each section thought with `parent_id` = root. Stream already loads `comments` (children) and shows them under the root; tag filter already pulls in roots when any child has the tag.
- **Tags:** When chunking on the server, apply the same `metadata.tags` (and doc tag from `plan_slug` / `doc_type`) to the root and to every section thought. No schema change.

### 4. Display (already mostly there)

- Stream by tag already shows root + full section content for document-style thoughts.
- **Optional improvement:** Show `source_metadata.section_title` in the UI (e.g. next to each section) so users can scan section names. Not required for MVP.

### 5. Ordering of sections

- **Current:** Sections are ordered by `created_at` (and loaded with `comments` ordered by `created_at`). As long as the server creates sections in document order, order is correct.
- **Optional:** Add something like `source_metadata.section_index` (integer) when creating chunks, and order children by it in Stream so order is stable even if creation timestamps are close or ever out of order.

### 6. Where to implement

- **Backend:** Laravel (IdeaTub). New or updated MCP handler; shared “chunking” logic (split by headings, build payloads) that can be unit-tested.
- **No frontend change required** for basic “chunk on save” — existing Stream and tag behaviour already display linked thoughts. Optional: small UI tweaks for section titles.

---

## Implementation outline (if you build server-side chunking)

1. **Chunking helper**  
   - Pure function: `(string $content, string $headingPattern?) => array of ['title' => string, 'content' => string]`.  
   - Default: split at `^#{1,6}\s+.+` (markdown ATX headings). First segment = content before first heading (title e.g. “Intro”).  
   - Tests: various heading levels, no headings (single chunk), empty content.

2. **Create root + section thoughts**  
   - In `McpController` (or a small service): given full content + options (doc_type, plan_slug, tags, project, file_path, create_root?: bool):  
     - Run chunking helper.  
     - If create_root: create one thought for “root” (e.g. first chunk or a short title line), with same tags/source_metadata (e.g. section_title = doc title).  
     - For each section (in order): create thought with same tags and source_metadata (section_title, plan_slug, doc_type, file_path, project), `parent_id` = root if present.  
   - Reuse existing embedding + metadata extraction per thought (or batch if you add that later).

3. **MCP surface**  
   - Either new tool `capture_document` with params: content, doc_type, plan_slug?, tags?, project?, file_path?, create_root? (default true).  
   - Or `capture_plan` with `auto_chunk: true` and same params; when true, ignore single-section semantics and run the chunk flow above.  
   - Return: e.g. `{ root_id, section_ids[], plan_slug }` so the client can show “saved as N sections”.

4. **Tests**  
   - Unit tests for chunking (headings, no headings, edge cases).  
   - Feature test: call MCP with long body, assert N thoughts created, same tag on all, all (or all but root) have parent_id, Stream by tag shows root + sections in order.

5. **Docs**  
   - Update `CLAUDE.md` / sync rules: “For a long document you can send the full content with auto_chunk (or capture_document) and IdeaTub will split at headings and link sections.”

---

## Summary table

| Aspect              | Current                         | Recommended                                              |
|---------------------|----------------------------------|----------------------------------------------------------|
| Split at titles     | Client splits, multiple calls   | Add server-side split at `##` / `###` (and optionally `#`) |
| Link chunks         | Via `parent_id` (root → sections)| Same; optional root thought, sections with parent_id     |
| Tags on all chunks  | Client passes same tags per call | Server applies same tags to root + every section         |
| Single doc submit   | Not supported                    | New tool or `capture_plan` with `auto_chunk: true`       |
| Display             | Stream shows root + comments     | Keep; optionally show section_title in UI                |

This plan gives you a clear path to “one thought or section” storage, linked display, and shared tags, with the option to submit a large research document once and have IdeaTub chunk it on save.

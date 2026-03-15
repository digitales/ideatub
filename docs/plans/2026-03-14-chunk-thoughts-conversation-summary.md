# Chunk thoughts on save — Conversation summary

**Date:** 2026-03-14  
**Type:** Implementation summary (from conversation)  
**Project:** ideatub

---

## Summary

We planned and implemented **server-side chunking** for IdeaTub: when saving a thought (web, MCP, API, or email), content over **500 words** is automatically split at markdown headings (`##`, `###`, etc.) into multiple linked thoughts (root + sections), with the same tags on all. Users can opt out via **no_chunking** (or “Don’t split” on web; `[no-chunk]` in email).

---

## What we built

1. **ThoughtChunkingService** — Word count (>500), split at ATX headings, `shouldChunk()` and `isNoChunkingRequested()` so all entry points share the same rules.
2. **ThoughtCaptureService** — Single place to create one thought or chunked thoughts. Accepts content, user_id, parent_id, source, source_metadata, no_chunking, and optional plan-style options (plan_slug, doc_type, file_path, project, extra_tags). Returns either one thought or root + section_ids. Never chunks when parent_id is set (replies stay single).
3. **All save paths use the service** — Web form, MCP capture_plan, MCP capture_thought, POST /api/thoughts, and inbound email now call `ThoughtCaptureService::create()` so behaviour is consistent.

---

## Entry points and opt-out

| Entry point | Chunking | Opt-out |
|-------------|----------|--------|
| Web form | Yes (>500 words) | Checkbox “Don’t split into sections” |
| MCP capture_plan | Yes | Params `no_chunking` / `no-chunking` |
| MCP capture_thought | Yes (when no parent) | Params `no_chunking` / `no-chunking` |
| POST /api/thoughts | Yes | Body `no_chunking` (boolean) |
| Inbound email | Yes | Subject or body contains `[no-chunk]` or `[no_chunking]` |

---

## Why not in the model

Chunking was kept **out of the Thought model** because: (1) one “save” can create multiple thoughts; (2) chunking needs OpenRouter (embed, metadata) and request/options; (3) the model should stay a simple single-thought entity. The shared **ThoughtCaptureService** is the single place for create-one vs create-chunked.

---

## Key files

- `app/Services/ThoughtChunkingService.php` — Threshold, word count, split at headings, no_chunking check.
- `app/Services/ThoughtCaptureService.php` — create(options) → one thought or root + sections.
- `app/Http/Controllers/IdeaController.php` — Uses captureService; web checkbox.
- `app/Http/Controllers/Api/McpController.php` — capture_thought and capture_plan use captureService.
- `app/Http/Controllers/Api/ThoughtsApiController.php` — store() uses captureService; returns chunked + section_ids when applicable.
- `app/Services/PostmarkInboundService.php` — Uses captureService; email opt-out via `[no-chunk]`.

---

## Plan doc

Full plan and recommendations: `docs/plans/2026-03-14-chunk-thoughts-on-save.md`.

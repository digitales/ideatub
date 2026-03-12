# Thought source — Design

**Date**: 2026-03-12  
**Status**: Approved  
**Summary**: Add a `source` (and optional `source_metadata`) to thoughts so we can record whether a thought came from the web, MCP (and which client, e.g. ChatGPT/Claude/Cursor), or in future calendar. Design allows future calendar integration without further schema change.

---

## 1. Data model

- **`source`** (new column): string, nullable, indexed. Max length 64.
  - **Web:** Set to `web` when created via the web UI.
  - **MCP:** Set to the client-provided value when present and non-empty (e.g. `chatgpt`, `claude`, `cursor`); otherwise `mcp`.
  - **Future:** e.g. `calendar` when thoughts are created from calendar integrations.
  - No DB enum; free-form string so new sources don’t require migrations.

- **`source_metadata`** (new column): JSON, nullable. Optional extra data per source.
  - MCP: optional client/vendor fields if needed later.
  - Future calendar: e.g. `{ "event_id": "...", "calendar_id": "...", "provider": "google" }`.

- **Existing `metadata`** remains the OpenRouter-extracted content metadata (tags, etc.); we do not store source or calendar data there.

- **Backward compatibility:** Both new columns nullable. Existing rows keep null; new thoughts always get `source` set.

---

## 2. Backend behaviour

- **Web (IdeaController):** On create, set `source = 'web'`, `source_metadata = null`.

- **MCP (McpController `capture_thought`):**
  - Accept optional `source` (string) and optional `source_metadata` (object) in tool params.
  - If `source` present and non-empty after trim: use it (max 64 chars).
  - If missing or empty: use `'mcp'`.
  - Store `source_metadata` as JSON when provided and valid (must be object); otherwise null.

- **Validation:** `source` optional, string, max 64. `source_metadata` optional, object only (reject non-object). No deep schema for now.

---

## 3. API surface

- **MCP**
  - **capture_thought:** Optional params `source`, `source_metadata`. Response unchanged (e.g. `{ "id": "..." }`).
  - **search_thoughts** and **browse_recent:** Include `source` and `source_metadata` in each thought in the response.
  - **thought_stats:** Unchanged this iteration (no filter by source).

- **Web:** IdeaController passes `source` (and optionally `source_metadata`) to the view so the UI can display (and optionally filter) by source.

- **Null handling:** Existing thoughts have null `source`/`source_metadata`; APIs and UI treat null as “no value” and do not break.

---

## 4. UI and future calendar

- **UI (this iteration)**
  - Thought list / search: show a small source label per thought (e.g. “Web”, “Claude”, “ChatGPT”, “Cursor”, “MCP”). **If `source` is null, show nothing.**
  - Optional: filter/tab by source only if low effort and UI has room; otherwise defer.
  - Detail view: same label; show `source_metadata` only when present and useful.
  - Web capture form unchanged; always sets `source = 'web'`.

- **Future calendar**
  - Set `source = 'calendar'` (or similar) when creating thoughts from calendar.
  - Use `source_metadata` for event/calendar ids and provider; no extra columns needed.
  - Display: show “Calendar” (or provider) as source label; optionally link back using `source_metadata`.

---

## 5. Implementation notes

- **Migration:** Add `source` (nullable string, 64, index) and `source_metadata` (nullable json) to `thoughts`. No backfill of existing rows.

- **Model:** Add both to `Thought::$fillable`; cast `source_metadata` to `array`. Optional: accessor to human-readable source label for UI (e.g. `web` → “Web”, `chatgpt` → “ChatGPT”).

- **Tests:** Unit or feature test that web-created thought has `source = 'web'`; MCP without param has `source = 'mcp'`; MCP with `source: 'claude'` stores it; validation rejects invalid `source_metadata`. Optional: test that search_thoughts/browse_recent include source fields.

- **Docs:** Update MCP/integration docs to describe optional `source` and `source_metadata` on `capture_thought` and that list responses include them.

---

## References

- [decisions/project-spec.md](../../decisions/project-spec.md) — MCP and user isolation
- [app/Models/Thought.php](../../app/Models/Thought.php) — Thought model
- [app/Http/Controllers/Api/McpController.php](../../app/Http/Controllers/Api/McpController.php) — MCP tools

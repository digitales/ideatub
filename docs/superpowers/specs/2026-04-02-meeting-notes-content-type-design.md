# Design: Meeting notes as a first-class content type

**Status:** Approved (2026-04-02)  
**Approach:** First-class type — MCP `capture_plan` + Stream tab + navigation (same pattern as Research / Plans).

## Goal

Support meeting notes as a named document type in IdeaTub: predictable capture from agents, a dedicated Stream collection, type badges that link to that collection, and tag semantics consistent with other `capture_plan` doc types.

## Non-goals

- Changing how research or plans infer `metadata.type` from the LLM (optional follow-up).
- New UI beyond a Stream tab, nav link, empty state, and existing card/badge behavior.

## Data model and capture

### MCP contract

- Add **`meeting`** to the allowed `doc_type` values for `capture_plan`.
- Behavior matches other doc types:
  - **`source`** on the thought = `meeting`.
  - **`source_metadata`** includes `doc_type`, optional `file_path`, `plan_slug`, `section_title`, `project` as today.
  - When **`plan_slug`** is set, add tag **`meeting:<slug>`** (lowercased slug) so long-form Stream tag views work.

### Reliable Stream listing

Research and Plans streams filter by **`metadata->type`** (`matchingCanonicalMetadataType`), not by `source`. Therefore:

- For **`doc_type: meeting`**, after metadata extraction (and for both single-thought and chunked creates), **set `metadata['type']` to `meeting`** so the Meetings stream query is deterministic and does not depend on the extractor returning `meeting`.

**Implementation locus:** `ThoughtCaptureService` in `createOne` and `createChunked`, after building `$metadata` from `OpenRouterService::extractMetadata`, merge or override: if `$docType === 'meeting'`, set `$metadata['type'] = 'meeting'`.

### Aliases

- In **`ThoughtTypeNavigation`**, use **`stored_values`:** `['meeting', 'meetings']` so `normalizeTypeKey` / `resolveThoughtToTypeKey` accept plural metadata from ad-hoc captures or the extractor.

### Ad-hoc `capture_thought`

- If a thought only has extractor output `metadata.type = meeting` (or `meetings`), **`resolveThoughtToTypeKey`** should resolve to the meetings collection once `meeting` is registered. Canonical agent path remains **`capture_plan` with `doc_type: meeting`**.

## MCP and documentation

- **`app/Http/Controllers/Api/McpController.php`:** extend `$allowedDocTypes`, tool description, and inline comments for `capture_plan`.
- **`tests/Feature/McpApiTest.php`:** reject invalid `doc_type`; happy path for `meeting` asserting `source`, `metadata.type`, tag when `plan_slug` present.
- **`docs/mcp-integration-guide.md`:** document `meeting` in the `doc_type` list and capture examples.
- **`CLAUDE.md`:** mention `meeting` alongside other `capture_plan` doc types where doc types are listed (if applicable).
- **Cursor MCP tool descriptor** (e.g. project `mcps/.../capture_plan.json`): keep `doc_type` description in sync if maintained manually.

## Web GUI

### Navigation

- **`ThoughtTypeNavigation`:** add canonical key **`meeting`**:
  - `collection_label`: **Meetings**
  - `thought_label`: **Meeting**
  - `route_name`: **`idea.stream.meetings`**
  - `stored_values`: **`meeting`, `meetings`**
- **Order:** append after **Plans** (Jira → Emails → Research → Plans → Meetings).

### Routing and controller

- **`routes/web.php`:** `GET /stream/meetings` named `idea.stream.meetings`.
- **`IdeaController::streamMeetings`:** mirror `streamResearch` / `streamPlans`: `visibleInStream`, `topLevel`, `matchingCanonicalMetadataType('meeting')`, same pagination and AJAX JSON shape as other typed streams.

### Views

- **`resources/views/idea/stream.blade.php`:** empty state when `$__collectionKey === 'meeting'`.
- **`idea/partials/stream_type_nav`:** no change required if it iterates `orderedNavTypes()`.

### Thought detail and cards

- **`thought_type_badge`** uses `resolveThoughtToTypeKey` and `routeName`; no structural change once navigation defines `meeting`.

### Homepage recent list

- **Include meetings** on the idea index recent list (same as plans). Do **not** add `excludingMeetings()` or equivalent on `IdeaController::index` — only research (and Jira) exclusions apply as today.

## Testing checklist

| Area | Tests |
|------|--------|
| Navigation | `ThoughtTypeNavigationTest`: ordered keys, labels, routes, `resolveThoughtToTypeKey` for `meeting` / `meetings` |
| Stream | `StreamPageTest`: Meetings in nav, route OK, active tab |
| Thought show | `ThoughtShowPageTest`: badge “Meeting” links to `idea.stream.meetings` |
| MCP | `McpApiTest`: validation + capture with `doc_type: meeting` and `metadata.type` |
| Capture | Covered by MCP test or dedicated `ThoughtCaptureService` test if preferred |

## Edge cases

- **`parent_id` (section rows):** Chunked flow copies the same `$metadata` to children; forced `type: meeting` applies to root and sections, keeping hierarchy consistent.
- **Invalid `doc_type`:** Existing JSON-RPC / validation behavior unchanged.

## Implementation order (suggested)

1. `ThoughtCaptureService` metadata override for `meeting`.
2. `McpController` + `McpApiTest`.
3. `ThoughtTypeNavigation` + `resolveThoughtToTypeKey`.
4. Route + `IdeaController::streamMeetings` + `stream.blade.php` empty state.
5. Stream / thought show / navigation unit and feature tests.
6. Docs and MCP descriptor.

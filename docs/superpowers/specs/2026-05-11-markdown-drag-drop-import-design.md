# Markdown Drag-and-Drop Import for Projects

**Date:** 2026-05-11
**Status:** Draft
**Scope:** Add drag-and-drop markdown file import to the project detail page

## Summary

Users can drag one or more `.md` files onto a project detail page to import them as thoughts linked to that project. A modal allows editing titles (defaulting to filename), selecting a shared content type, and previewing rendered markdown before confirming. Built by extending the existing `FileImportService` and using the server-side `SafeCommonMarkConverter` for preview rendering.

## Requirements

- Drag-and-drop zone on the project detail page (`projects/show.blade.php`)
- Support multiple files in a single drop
- Editable title per file, defaulting to filename without `.md` extension
- Shared content type selector: Thought, Meeting, Research, Plan, Decision, Spec
- Rendered markdown preview per file (server-side, matching app rendering)
- Non-`.md` files filtered out with a visible note
- Extends existing `FileImportService` -- no new service class

## Content Type Mapping

The six user-facing types map to backend fields as follows:

| UI Label   | `source` value | `source_metadata.doc_type` | Post-create hook                              |
|------------|----------------|----------------------------|-----------------------------------------------|
| Thought    | `upload`       | *(not set)*                | None                                          |
| Meeting    | `meeting`      | `meeting`                  | `MeetingService::queueAutoRunForMeetingThought` |
| Research   | `research`     | `research`                 | None                                          |
| Plan       | `plan`         | `plan`                     | None                                          |
| Decision   | `decision`     | `decision`                 | None                                          |
| Spec       | `spec`         | `spec`                     | None                                          |

All types store the title in `metadata.title`.

## Architecture

### 1. Drop Zone (Frontend)

An Alpine.js component (`x-data="mdDropZone"`) added to `projects/show.blade.php`, positioned below the existing "Add thought" section.

**Visual states:**
- **Idle:** Dashed border area with icon and "Drop .md files here" text
- **Dragover:** Highlighted border (e.g. blue/indigo), background tint
- **Processing:** Spinner while files are being read and previews fetched

**Client-side behavior on drop:**
1. Read dropped files via `FileReader.readAsText()`
2. Filter to `.md` extensions only; count skipped files
3. Reject empty files and files exceeding 1MB
4. Open the import modal with valid files

### 2. Import Modal (Frontend)

Part of the same Alpine component. Contains:

- **Content type selector** (top) -- dropdown or button group, defaults to "Thought", shared across all files
- **File list** -- one row per valid file:
  - Editable title input (pre-filled: filename minus `.md`)
  - Rendered markdown preview in a `prose prose-sm` container
  - Remove button to exclude a file
- **Skipped files note** -- "N file(s) skipped -- only .md supported" (shown only when applicable; combines non-`.md`, empty, and oversized counts)
- **Action buttons** -- "Cancel" and "Import N file(s)"

**Preview fetching:** On modal open, each file's raw content is POSTed to the preview endpoint. Previews load independently (one request per file). If a preview request fails, the raw markdown is displayed in a `<pre>` block as fallback.

### 3. Preview Endpoint (Backend)

**Route:** `POST /imports/preview-markdown`
**Controller:** `ImportController::previewMarkdown`
**Middleware:** `auth` (session)

**Request:**
```json
{
  "content": "# My markdown content..."
}
```

**Validation:**
- `content` required, string, max 1MB

**Processing:**
1. Strip YAML front matter via `MarkdownDisplayHelper`
2. Render via `SafeCommonMarkConverter`
3. Return HTML fragment

**Response:**
```json
{
  "html": "<h1>My markdown content...</h1>"
}
```

No database writes. No embedding. No AI processing.

### 4. Import Endpoint (Backend)

**Route:** `POST /projects/{project}/import-markdown`
**Controller:** `ImportController::importMarkdown`
**Middleware:** `auth` (session)

**Request:**
```json
{
  "type": "meeting",
  "files": [
    { "title": "Weekly standup notes", "content": "# Weekly standup..." },
    { "title": "Retro Q2", "content": "## Retro..." }
  ]
}
```

**Validation:**
- `type` required, in: `thought`, `meeting`, `research`, `plan`, `decision`, `spec`
- `files` required, array, min 1
- `files.*.title` required, string, max 255
- `files.*.content` required, string, max 1MB

**Processing per file:**
1. Call `FileImportService::importMarkdownWithMetadata()` (new method)
2. Which delegates to `ThoughtCaptureService::create()` with:
   - `content`: the raw markdown
   - `source`: mapped per content type table above
   - `metadata.title`: user-provided title
   - `source_metadata.doc_type`: mapped per table (omitted for plain Thought)
   - `source_metadata.file_path`: original filename
3. Link to project via `ProjectMembershipService::addThought()`
4. For `meeting` type: queue `MeetingService::queueAutoRunForMeetingThought()`

**Response (JSON):**
```json
{
  "imported": [
    { "id": "uuid-1", "title": "Weekly standup notes", "status": "success" },
    { "id": "uuid-2", "title": "Retro Q2", "status": "success" }
  ],
  "failed": []
}
```

On success with no failures, the frontend redirects back to the project page with a success flash message.

### 5. FileImportService Extension

New method on `FileImportService`:

```
importMarkdownWithMetadata(
    string $content,
    string $title,
    string $type,
    Project $project,
    User $user,
    ?string $originalFilename = null
): Thought
```

This method:
1. Maps the `type` to `source` and `source_metadata.doc_type` per the content type table
2. Calls `ThoughtCaptureService::create()` with the mapped values
3. Links the created thought to the project via `ProjectMembershipService::addThought()`
4. Queues meeting processing if `type === 'meeting'`
5. Returns the created `Thought`

Standard auto-chunking applies (thoughts exceeding 500 words are split at markdown headings).

## Error Handling

| Scenario | Handling |
|----------|----------|
| Non-`.md` files in drop | Filtered client-side; note shown in modal |
| Empty files | Filtered client-side; counted in skipped note |
| Files > 1MB | Rejected client-side; counted in skipped note |
| Preview endpoint fails | Raw markdown shown in `<pre>` as fallback |
| Import fails for a file | Partial success: report per-file status in response |
| All files filtered/empty | Modal does not open; flash message explains why |
| Duplicate content | No dedup enforced; two drops create two thoughts |

No rollback on partial failure -- successfully imported files remain.

## Testing

### Pest Unit/Feature Tests

- `ImportController::previewMarkdown`
  - Returns valid HTML for markdown input
  - Strips YAML front matter
  - Rejects empty content (422)
  - Requires authentication (401)

- `ImportController::importMarkdown`
  - Creates thoughts with correct `source`, `metadata.title`, `source_metadata.doc_type`
  - Links created thoughts to the specified project
  - Validates type enum (422 for invalid)
  - Rejects oversized content (422)
  - Queues meeting processing for `meeting` type
  - Handles multi-file import (correct count, all linked)
  - Requires authentication (401)
  - Requires project ownership/access

- `FileImportService::importMarkdownWithMetadata`
  - Delegates to `ThoughtCaptureService::create()` with correct params per type
  - Returns created `Thought` model

### Manual Testing Checklist

- [ ] Drag single `.md` file -- modal opens, preview renders, title editable, import succeeds
- [ ] Drag multiple `.md` files -- all listed, shared type selector, individual titles
- [ ] Drag mix of `.md` and `.txt` files -- non-`.md` filtered, note shown
- [ ] Change type to Meeting -- verify meeting processing queued after import
- [ ] Edit title before import -- custom title persists on the created thought
- [ ] Drop empty `.md` file -- filtered out with note
- [ ] Drop file > 1MB -- filtered out with note
- [ ] All dropped files invalid -- modal does not open, message shown

## Files Changed

| File | Change |
|------|--------|
| `resources/views/projects/show.blade.php` | Add drop zone markup and import modal |
| `resources/js/app.js` | Add `mdDropZone` Alpine component |
| `app/Http/Controllers/ImportController.php` | Add `previewMarkdown` and `importMarkdown` methods |
| `app/Services/Import/FileImportService.php` | Add `importMarkdownWithMetadata` method |
| `routes/web.php` | Add two new routes |
| `tests/Feature/ImportMarkdownTest.php` | New test file |

## Out of Scope

- Drag-and-drop on pages other than project detail
- Non-markdown file types (`.txt`, `.pdf`, `.docx`)
- Client-side markdown rendering / new JS dependencies
- Deduplication of identical content
- Frontend JS test suite

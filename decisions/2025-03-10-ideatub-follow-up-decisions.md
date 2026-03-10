# IdeaTub — Follow-up architecture decisions

> **Superseded:** This content is consolidated into [project-spec.md](./project-spec.md). Kept for reference only.

**Date**: 2025-03-10  
**Status**: Superseded  
**Context**: Clarifications from project-spec analysis for Web UI, Evernote mirror, auth, and implementation order.

---

## 1. User isolation and auth

**Decision:** One key per user; users are isolated from each other.

**Consequences:**
- Need a **User** model (or equivalent) and per-user access keys.
- Web auth is not a single `BRAIN_WEB_KEY`; it is per-user (e.g. user-scoped API key or session after key login).
- Thoughts, MCP access, and Evernote sync must be scoped by user (e.g. `user_id` on `thoughts`, MCP key → user, Evernote config per user or per-user token).

**Alternatives considered:** Single shared key for the whole app; rejected to support multi-user isolation.

---

## 2. Search UX and routing

**Decision:** Search is driven by a **GET query variable** (e.g. `?q=...`). Same page can serve index and search; no separate `/search` required if the variable is present.

**Consequences:** Routes can be `GET /` (and `GET /?q=...` for search), `POST /thoughts` for capture. Single Blade view can show recent when `q` is empty and results when `q` is set.

---

## 3. Evernote mirror — update existing note

**Decision:** When a thought is **updated** (content or metadata), **update the existing Evernote note** in place. Do not create a new note and repoint `evernote_note_guid`.

**Consequences:**
- ThoughtObserver (or equivalent) must handle both `created` and `updated`; on update, call Evernote API to update the note by `evernote_note_guid`.
- EvernoteService needs an `updateNote($thought)` (or similar) path, not only create.

---

## 4. Evernote — notebook per type/tag

**Decision:** Support **notebook per type/tag** (or similar classification), not a single fixed notebook.

**Consequences:**
- Config or mapping (e.g. type/tag → `notebook_guid`) is required. Could live in config, DB, or user settings.
- EvernoteService must resolve target notebook from thought metadata (e.g. `metadata.type`, `metadata.tags`) and optionally user.

---

## 5. Evernote sync idempotency

**Decision:** Idempotency is **“create note once and set `evernote_note_guid`”**. No separate idempotency key or sync-status field; duplicate syncs are avoided by checking `evernote_note_guid` before create.

**Consequences:** On create, if `evernote_note_guid` is already set, skip create. Retries after partial failure must be safe (e.g. job receives same thought; second run skips create). Update path uses existing GUID.

---

## 6. MCP/capture documentation location

**Decision:** Document “save this” / “remember this” and how data from ChatGPT/Cursor/Claude enters the app in **in-repo docs** (e.g. `docs/` or `/docs`), not only README.

**Consequences:** Add or update in-repo documentation that explains MCP tool usage and user phrasing for capture; README can link to it.

---

## 7. Laravel Cloud and queues

**Decision:** Run on **Laravel Cloud with queues**. Evernote sync (and any other heavy or external work) should be **queued**, not done synchronously in the request.

**Consequences:**
- Evernote sync (and optionally embed + metadata extraction for web capture) should be dispatched to a queue job.
- ThoughtObserver (or controller) dispatches a job; job calls EvernoteService. Sync failures are handled in the job (log, retry, alert), not in the HTTP response.

---

## 8. Feature order

**Decision:** Implement **Web UI first**, then **Evernote mirror**.

**Consequences:** Specs and implementation order: (1) User model + per-user auth, (2) Web UI (search via GET, capture, recent), (3) Evernote mirror (create + update, notebook per type/tag, queued sync). Slack and MCP remain as-is; Evernote comes after web.

---

## 9. Relationship to Vinlytic

**Decision:** IdeaTub is **isolated from Vinlytic**. Separate app/repo; no shared code or database with the vehicle analytics project.

**Consequences:** No dependency on Vinlytic; this repo is standalone IdeaTub context only.

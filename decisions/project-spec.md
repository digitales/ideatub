# IdeaTub — Project context and initial decisions

**Date**: 2025-03-10  
**Status**: Accepted  

This document is the single source of product decisions, planned features, and implementation context for the IdeaTub Laravel app. It consolidates the initial architecture decisions. Use it as imported context when working on the codebase in its own repository.

### Implementation progress (2025-03-10)

| Phase | Description | Status |
|-------|-------------|--------|
| **0** | Bootstrap: pgvector, Thought model, OpenRouterService, MCP API (POST /api/mcp) | ✅ Done |
| **1** | User isolation: user_id on thoughts, user_mcp_keys, MCP key → user, all tools scoped by user | ✅ Done |
| **2** | Web GUI: IdeaController (GET /, POST /thoughts), Blade view (search, capture, recent), auth, README + .env.example | ✅ Done |
| **3** | Evernote mirror: evernote_note_guid, EvernoteService, SyncThoughtToEvernote job, model events, docs | ✅ Done |

**Branch:** `feature/ideatub-implementation`. **Plan:** `docs/superpowers/plans/2025-03-10-ideatub-implementation.md`. Slack remains out of scope.

---

## 1. What this project is

- **IdeaTub (Laravel):** A Laravel implementation of the [Open Brain Complete Setup Guide](https://promptkit.natebjones.com/20260224_uq1_guide_main). One database (PostgreSQL + pgvector), one AI gateway (OpenRouter), multiple capture and read surfaces.
- **Scope:** Personal knowledge system with semantic search. Thoughts are embedded, classified (type, tags, people, action items), and stored. **Primary capture:** a simple web GUI for input and comments. MCP-connected AI can search and write via tools. Slack is out of scope for initial focus.
- **Hosting:** Designed to run on **Laravel Cloud** with Serverless Postgres and **queues**. No Supabase; everything in Laravel.
- **Isolation:** IdeaTub is **isolated** from other projects (e.g. Vinlytic); own repo/app. No shared code or database.

---

## 2. Current implementation (as built / target)

- **Capture:** Simple web GUI — form for new thoughts; submit → embed + metadata (OpenRouter) → store in `thoughts` table. MCP tool `capture_thought` also writes thoughts (e.g. when user says “remember this” in chat). Comment-on-thought is future work; Slack is not in scope.
- **Retrieval:** MCP over HTTP at `POST /api/mcp`. Auth: per-user key via `?key=...` or `x-ideatub-key` header (resolved via `user_mcp_keys`). Tools: `search_thoughts`, `browse_recent`, `thought_stats`, `capture_thought`, all scoped by user. Web: `GET /?q=...` (semantic search) and recent list on same page.
- **Storage:** PostgreSQL with pgvector. Table `thoughts`: `id` (uuid), `user_id`, `content`, `embedding` (vector 1536), `metadata` (json), `evernote_note_guid` (nullable), `created_at`, `updated_at`. Table `user_mcp_keys`: per-user MCP keys (key_hash, label, last_used_at).
- **Services:** `OpenRouterService` (embed + extract metadata); `Thought` model with `HasNeighbors` for cosine similarity search; `EvernoteService` (create/update note, notebook from type/tag); `SyncThoughtToEvernote` job on Thought::created/updated (queued, skip if Evernote not configured).

---

## 3. Auth and user isolation

- **Decision:** One key per user; users are isolated from each other.
- **User** = one human (one IdeaTub account). Their thoughts are isolated from other users.
- **Consequences:**
  - Need a **User** model (or equivalent) and per-user access keys.
  - Web auth is per-user (e.g. user-scoped API key or session after key login), not a single shared env key.
  - Thoughts, MCP access, and Evernote sync must be scoped by user (e.g. `user_id` on `thoughts`, MCP key → user, Evernote config per user or per-user token).
- **Alternatives considered:** Single shared key for the whole app; rejected to support multi-user isolation.

### 3.1 MCP auth and multiple AI agents

- **MCP key** identifies the **user**, not the AI agent. One user can have one or more MCP keys (e.g. one key they paste into every client, or separate keys per client for revoking).
- **AI agent** = the MCP client (Cursor, Claude desktop, ChatGPT with MCP, etc.). The agent is not a first-class identity; it’s just the tool caller. All authorization is “which user does this key belong to?”

**How it works:**

1. **Per-user MCP keys** — Each user has at least one MCP access key (e.g. generated in the web UI or via CLI, stored hashed). The key is a long, opaque secret (e.g. `ideatub_...` or UUID).
2. **Same key in every agent** — The user configures **the same key** in each AI product they use (Cursor, Claude, ChatGPT). Same MCP server URL + same key.
3. **Request flow** — Request hits `POST /api/mcp` with `?key=USER_MCP_KEY` or `x-ideatub-key: USER_MCP_KEY`. Backend resolves key → **User**. All MCP tools run in that user’s context: filter and write to that user’s thoughts only.
4. **No “per-agent” auth** — We do **not** require a different key per AI agent. The same key in Cursor and in ChatGPT means “same user, same thought store.” Optionally add per-key labels (e.g. “Cursor”, “Claude”) for audit or display only.

**Optional: multiple keys per user** — User can create several MCP keys (e.g. “Cursor”, “Claude”, “ChatGPT”). Same permissions; revoke one without affecting others. Implementation: table `user_mcp_keys` (or similar) with `user_id`, `key_hash`, optional `label`, `last_used_at`.

| Question | Answer |
|----------|--------|
| Who does the key identify? | The **user** (human account). |
| Do different AI agents need different keys? | No. Same key everywhere = same user, same data. |
| Can one user have multiple keys? | Optional; useful for revoking per client or labelling. |
| Where is the key sent? | Query param `?key=...` or header `x-ideatub-key`. |

### 3.2 API keys and visibility

- **Decision:** No server-side API keys (OpenRouter, Slack, Evernote, etc.) may be exposed to users. Per-user MCP keys are only visible to the owning user and must be handled so they do not leak.

**Server-only keys (never visible to any user):**

- **OpenRouter** — Used by IdeaTub for embeddings and metadata. Store in `.env` (e.g. `OPENROUTER_API_KEY`). Never send to the browser, never include in API responses or frontend config. Only Laravel server-side code may read it.
- **Slack** — (Out of scope for now.) If added later: signing secret / tokens in `.env`; server-only.
- **Evernote** — App credentials and (where applicable) per-user tokens. Store in `.env` or encrypted in DB. Never expose in UI or responses to other users.

**Per-user MCP key:**

- The **owning user** must see their key once to copy it into Cursor/Claude (e.g. “Your MCP key” in settings, shown on creation). After that, show only a masked version (e.g. `ideatub_••••••••`) or “Regenerate” — never display the full secret again unless they regenerate.
- Store only a **hash** of the key in the DB (`user_mcp_keys.key_hash`). Never store or log the plain key. Prefer **header** `x-ideatub-key` over query param for MCP auth so the key is less likely to appear in server access logs or referrers. If query param is supported, ensure request logging does not log query string or strip the key.
- Other users must never see another user’s MCP key (enforce by user scoping in the UI and API).

**Implementation:** Use `.env` for all server secrets; never pass them to Blade/JS. In logging and error reporting, redact or omit keys and tokens. Document in README that `.env` must not be committed and is not sent to the client.

---

## 4. Decisions and planned work

**Feature order:** (1) User model + per-user auth, (2) **Simple web GUI** (search, input, comments), (3) Evernote mirror. MCP remains as-is; Evernote comes after web. Slack is not in scope.

### 4.1 Web interface — *implemented*

- **Decision:** Focus on a **simple web GUI** for input and comments. Users search, add thoughts, and view recent items in the browser. This is the primary capture and read surface.
- **Auth:** Per-user (see §3). Auth middleware and user scoping for all web routes.
- **Planned behaviour:**
  - **Search:** One page with a search box; submit runs semantic search via a **GET query variable** (e.g. `?q=...`). Same as MCP `search_thoughts`; show results on the same page (full-page or simple JS). Same page serves index and search; no separate `/search` route when `q` is present.
  - **Capture / input:** Form to add a new thought (and comments if supported); same pipeline as MCP (embed + metadata → save). *Comment-on-thought or follow-up comments are **future work**; initial implementation is new-thought capture only.*
  - **Recent:** Show last N thoughts on the same page.
- **Implementation:** New `IdeaController` (index, search, store); routes `GET /` (and `GET /?q=...` for search), `POST /thoughts`; one Blade view (Tailwind).
- **Files to add/change:** User model/migration if not present; `app/Http/Controllers/IdeaController.php`, `resources/views/idea/index.blade.php`, `routes/web.php`, auth middleware for per-user key; `config/services.php` and `.env.example`; README.

### 4.2 Evernote mirror — *implemented*

- **Decision:** Use **Evernote as a mirror only**. Postgres remains the source of truth. Every new thought is synced to Evernote; when a thought is **updated**, **update the existing Evernote note** in place (do not create a new note).
- **Notebooks:** **Notebook per type/tag** — support mapping type/tag (or similar) to Evernote `notebook_guid` (config or user settings). EvernoteService resolves target notebook from thought metadata (e.g. `metadata.type`, `metadata.tags`) and optionally user.
- **Flow:** On `Thought::created`, dispatch a job that calls `EvernoteService::syncThought($thought)` (create note, set `evernote_note_guid`). On `Thought::updated`, dispatch a job that updates the existing note by `evernote_note_guid`. **Idempotency:** create only if `evernote_note_guid` is null; updates use existing GUID. No separate idempotency key or sync-status field.
- **Infrastructure:** Evernote sync is **queued** (job), not synchronous. Sync failures handled in job (log, retry); do not fail the HTTP request. If Evernote token/config is empty, skip sync.
- **Implementation:** `EvernoteService` (create + update note; resolve notebook from type/tag mapping); job(s) for sync; observer or model events dispatch jobs; migration adding `evernote_note_guid` to `thoughts`.
- **Files to add/change:** `app/Services/EvernoteService.php`, `app/Jobs/SyncThoughtToEvernote.php` (or similar), observer/events, migration for `evernote_note_guid`, `Thought` fillable, config for notebook mapping, `.env.example`, README.

### 4.3 MCP and “data from ChatGPT, Cursor, Claude”

- **Decision / fact:** Data from ChatGPT, Cursor, and Claude **enters the app only via MCP tool calls**. MCP does **not** push full chat transcripts.
- **What gets in:** Whatever the AI sends as `content` to `capture_thought` (e.g. when the user says “remember this: …”) and as `query` to `search_thoughts`. No automatic capture of every user message unless the AI is prompted to call `capture_thought`.
- **Implication:** Document in **in-repo docs** (e.g. `docs/`) that users should say “save this” / “remember this” in chat so the AI calls the tool. The **web GUI is the primary capture surface** for direct input and comments.

### 4.4 Companion Prompt Kit

- **Decision:** The app is designed to work with the [Open Brain Companion Prompts](https://promptkit.natebjones.com/20260224_uq1_promptkit_1).
- **Fit:**
  - **Prompt 1 (Memory Migration), 2 (Second Brain Migration), 5 (Weekly Review):** Require MCP connected; the AI calls `capture_thought` (1, 2) and `search_thoughts` / `browse_recent` / `thought_stats` (5). Current MCP server supports this.
  - **Prompt 4 (Quick Capture Templates):** Templates are for _what the user types_ into the capture channel or says to the AI. Same metadata pipeline (type, tags, people, action items) in `OpenRouterService::extractMetadata()`; works with the web form or “save this” in chat.
- **Action:** README (or docs) should link to the Prompt Kit and state that Prompts 1, 2, 5 need the Open Brain MCP URL; Prompt 4 templates can be used in the web GUI or chat.

---

## 5. Architecture summary

```
Capture:  Web GUI ──► POST /thoughts ──► OpenRouterService ──► Thought
          MCP     ──► tools/call capture_thought (per-user key)

Storage:  thoughts (Postgres + pgvector, user_id); optional mirror to Evernote (queued job)

Read:     MCP tools: search_thoughts, browse_recent, thought_stats (scoped by user)
          Web: GET /?q=... (semantic search), same page recent list
```

---

## 6. Repo intent

- **IdeaTub is intended to live in its own repository**, not as a subdirectory of another project (e.g. dark-factory). Move or copy the `open-brain` application (and this document) into a dedicated repo and use this file as the single source of context for future work.

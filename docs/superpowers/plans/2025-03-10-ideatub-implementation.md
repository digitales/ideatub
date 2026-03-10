# IdeaTub Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement IdeaTub per `decisions/project-spec.md`: per-user auth and MCP keys, **simple web GUI** (search, input, comments), then Evernote mirror. Primary capture is the web UI; Slack is out of scope. If the core (Thought, OpenRouter, MCP) does not exist, bootstrap it first.

**Architecture:** One Laravel app; PostgreSQL + pgvector for thoughts; per-user isolation via User model and MCP key → user resolution; **web GUI as primary capture**; MCP scoped by user; Evernote sync queued per thought.

**Tech Stack:** Laravel 12, PostgreSQL, pgvector, OpenRouter API, Tailwind CSS, Blade. Queues for Evernote sync.

**Source of truth:** `decisions/project-spec.md`

---

## Scope and feature order

- **Phase 0 (if needed):** Bootstrap IdeaTub core — Thought model, `thoughts` table, OpenRouterService, MCP endpoint. Single shared env key for MCP; no user isolation yet. (Slack out of scope.)
- **Phase 1:** User model + per-user auth — MCP keys table, key → user, `user_id` on thoughts, scope MCP by user.
- **Phase 2:** Simple web GUI — search (`GET /?q=...`), input/comments (`POST /thoughts`), recent; IdeaController; Blade view; per-user auth.
- **Phase 3:** Evernote mirror — `evernote_note_guid`, EvernoteService, sync jobs, notebook mapping.

---

## File structure (target state)

| Path | Responsibility |
|------|----------------|
| `app/Models/User.php` | Existing; add `thoughts()`, `mcpKeys()` (Phase 1). |
| `app/Models/Thought.php` | Thought model; HasNeighbors for vector search; user_id (Phase 0/1). |
| `app/Models/UserMcpKey.php` | Per-user MCP key; key_hash, label, last_used_at (Phase 1). |
| `app/Services/OpenRouterService.php` | Embed + extractMetadata (Phase 0). |
| `app/Services/EvernoteService.php` | Create/update note; notebook from type/tag (Phase 3). |
| `app/Http/Controllers/Api/McpController.php` | POST /api/mcp; resolve key → user; tools (Phase 0/1). |
| *(Slack ingest)* | Out of scope; focus on web GUI for capture. |
| `app/Http/Controllers/IdeaController.php` | index (with ?q= search), store (Phase 2). |
| `app/Jobs/SyncThoughtToEvernote.php` | Queue job for create/update note (Phase 3). |
| `app/Http/Middleware/ResolveMcpUser.php` | Resolve MCP key to user; attach to request (Phase 1). |
| `database/migrations/*_create_thoughts_table.php` | id (uuid), user_id, content, embedding, metadata, timestamps (Phase 0/1). |
| `database/migrations/*_create_user_mcp_keys_table.php` | user_id, key_hash, label, last_used_at (Phase 1). |
| `database/migrations/*_add_evernote_note_guid_to_thoughts.php` | evernote_note_guid nullable (Phase 3). |
| `routes/api.php` | MCP routes (Phase 0). |
| `routes/web.php` | GET /, POST /thoughts; auth (Phase 2). |
| `resources/views/idea/index.blade.php` | Search box, results, capture form, recent list (Phase 2). |
| `config/services.php` | openrouter, evernote, notebook mapping (Phase 0/3). |
| `.env.example` | OPENROUTER_API_KEY, MCP_ACCESS_KEY (legacy), EVERNOTE_* (Phase 3). |

---

## Chunk 1: Phase 0 — Bootstrap IdeaTub core

*Skip this chunk if Thought, OpenRouterService, and MCP at POST /api/mcp already exist. Slack is out of scope.*

### Task 0.1: PostgreSQL and pgvector

**Files:**
- Modify: `config/database.php` (ensure pgsql connection)
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_enable_pgvector_extension.php` (if needed)

- [ ] **Step 1:** Add/enable pgvector in PostgreSQL (e.g. `CREATE EXTENSION IF NOT EXISTS vector;` in migration or documented in README).
- [ ] **Step 2:** Commit: "chore: enable pgvector for IdeaTub"

### Task 0.2: Thoughts table and Thought model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_thoughts_table.php`
- Create: `app/Models/Thought.php`

- [ ] **Step 1: Migration**

Create migration. Table `thoughts`: `id` uuid primary, `user_id` nullable bigInteger unsigned (for Phase 1; add later if doing Phase 0 only), `content` text, `embedding` vector(1536), `metadata` jsonb, `created_at`, `updated_at`. Index on `user_id`. If pgvector not yet in use, use raw DB::statement for vector column or document dependency.

- [ ] **Step 2: Thought model**

Create `App\Models\Thought` with fillable `content`, `metadata`, `user_id`; cast `metadata` to array; use package or trait for vector similarity (e.g. `HasNeighbors` or custom scope `nearestTo($embedding, $limit)`). If using a package (e.g. laravel-pgvector), add it and implement.

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`
Expected: thoughts table created.

- [ ] **Step 4: Commit**

"feat: add Thought model and thoughts table with pgvector"

### Task 0.3: OpenRouterService

**Files:**
- Create: `app/Services/OpenRouterService.php`
- Modify: `config/services.php` (add openrouter key)

- [ ] **Step 1:** Add `openrouter.api_key` to config (from env OPENROUTER_API_KEY).
- [ ] **Step 2:** Create OpenRouterService with methods: `embed(string $text): array` (returns vector), `extractMetadata(string $text): array` (returns type, tags, people, action_items or similar). Use OpenRouter API (e.g. embedding model + small completion for metadata). Implement and add unit tests if time.
- [ ] **Step 3: Commit**

"feat: add OpenRouterService for embed and metadata extraction"

### Task 0.4: MCP API route and controller

**Files:**
- Create: `routes/api.php` and register in `bootstrap/app.php`
- Create: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1:** Create `routes/api.php`. In `bootstrap/app.php` add `api: __DIR__.'/../routes/api.php'` (or equivalent for Laravel 12).
- [ ] **Step 2:** Add route `POST /api/mcp` to McpController. Auth: read `?key=...` or `x-brain-key` header; for Phase 0 use single env `MCP_ACCESS_KEY` and allow request if key matches.
- [ ] **Step 3:** Implement MCP handler: parse JSON-RPC style body; tools `search_thoughts`, `browse_recent`, `thought_stats`, `capture_thought`. For Phase 0 query Thought without user_id filter. Implement each tool (search via vector similarity, browse recent, stats count, capture = embed + metadata + save).
- [ ] **Step 4: Commit**

"feat: add MCP API with search_thoughts, browse_recent, thought_stats, capture_thought"

### Task 0.5: Slack (out of scope)

Slack ingest is **not** in scope. Primary capture is the web GUI (Phase 2). Skip Slack controller and routes.

---

## Chunk 2: Phase 1 — User model and per-user auth

### Task 1.1: user_id on thoughts

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_user_id_to_thoughts_table.php` (if thoughts already exist without user_id)
- Or: ensure Phase 0 migration includes `user_id` and foreign key to users.

- [ ] **Step 1:** Migration: add `user_id` to thoughts (nullable then backfill, or not null if new). Foreign key to users. Index.
- [ ] **Step 2:** Thought model: add `user_id` to fillable; add `belongsTo(User::class)`.
- [ ] **Step 3:** User model: add `hasMany(Thought::class)`.
- [ ] **Step 4: Commit**

"feat: scope thoughts by user_id"

### Task 1.2: User MCP keys table and model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_user_mcp_keys_table.php`
- Create: `app/Models/UserMcpKey.php`

- [ ] **Step 1:** Migration: table `user_mcp_keys`: id, user_id (FK), key_hash (string), label (nullable), last_used_at (nullable), timestamps. Unique on key_hash.
- [ ] **Step 2:** Model UserMcpKey: fillable key_hash, label, last_used_at; belongsTo User; User hasMany UserMcpKeys. Helper: `findByPlainKey(string $key): ?self` (hash and lookup).
- [ ] **Step 3:** Seeder or command to create one MCP key per user (e.g. `ideatub_` + random string), store hash. Document in README.
- [ ] **Step 4: Commit**

"feat: add user_mcp_keys and UserMcpKey model"

### Task 1.3: MCP key resolution and scope by user

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Optional: Create: `app/Http/Middleware/ResolveMcpUser.php`

- [ ] **Step 1:** Resolve key from query `key` or header `x-brain-key`. Look up UserMcpKey by plain key; get user. If not found, 401. Attach user to request (e.g. `$request->setUserResolver(fn () => $user)` or attribute).
- [ ] **Step 2:** All MCP tools: filter Thought by `user_id`; capture_thought sets thought.user_id to resolved user. Update last_used_at on the key.
- [ ] **Step 3: Commit**

"feat: MCP auth per-user; scope tools by user"

### Task 1.4: Slack (out of scope)

Slack is not in scope. User scoping is applied to web GUI and MCP only.

---

## Chunk 3: Phase 2 — Simple web GUI

### Task 2.1: Web auth and IdeaController (primary capture)

**Files:**
- Create: `app/Http/Controllers/IdeaController.php`
- Modify: `routes/web.php`

- [ ] **Step 1:** Ensure web auth is per-user (existing Laravel session auth: login/register). Protect Idea routes with `auth` middleware.
- [ ] **Step 2:** Routes: `GET /` → IdeaController@index (with optional `q`); `POST /thoughts` → IdeaController@store. Both under `middleware('auth')`. Optionally move existing `/` to another path if app has a different homepage.
- [ ] **Step 3:** IdeaController: index() — if request has `q`, run semantic search (Thought::nearestTo(embed($q))->where('user_id', auth()->id())); return view with results and query. Else return view with recent (last N thoughts for user). store(Request): validate content; OpenRouterService embed + extractMetadata; Thought::create with user_id = auth()->id(); redirect or return JSON.
- [ ] **Step 4: Commit**

"feat: add IdeaController and web routes for search and capture"

### Task 2.2: Blade view (search, capture, recent)

**Files:**
- Create: `resources/views/idea/index.blade.php`
- Optional: Modify: `resources/views/layouts/app.blade.php` or create idea layout

- [ ] **Step 1:** One page: search box (form GET /?q=...), display search results when `q` present; capture form (POST /thoughts); "Recent" section (last N thoughts). Use Tailwind. Same page for index and search (no separate /search route).
- [ ] **Step 2: Commit**

"feat: add idea index view with search, capture, recent"

### Task 2.3: README and config

**Files:**
- Modify: `README.md` or `docs/`
- Modify: `.env.example`

- [ ] **Step 1:** Document: IdeaTub setup, OPENROUTER_API_KEY, MCP URL and per-user key, Web login, link to Companion Prompt Kit (spec §4.4).
- [ ] **Step 2:** .env.example: OPENROUTER_API_KEY, MCP_ACCESS_KEY (optional legacy), database, queue.
- [ ] **Step 3: Commit**

"docs: IdeaTub setup and env example"

---

## Chunk 4: Phase 3 — Evernote mirror

### Task 3.1: evernote_note_guid and config

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_evernote_note_guid_to_thoughts_table.php`
- Modify: `app/Models/Thought.php` (fillable)
- Modify: `config/services.php`; `.env.example`

- [ ] **Step 1:** Migration: add `evernote_note_guid` nullable string to thoughts.
- [ ] **Step 2:** Thought fillable: add evernote_note_guid. Config: evernote token, notebook mapping (type/tag → notebook_guid).
- [ ] **Step 3: Commit**

"feat: add evernote_note_guid and Evernote config"

### Task 3.2: EvernoteService

**Files:**
- Create: `app/Services/EvernoteService.php`

- [ ] **Step 1:** EvernoteService: resolve target notebook from thought metadata (type/tags) and config; createNote(Thought) when evernote_note_guid is null; updateNote(Thought) when set. Use Evernote API/SDK. Skip if token empty.
- [ ] **Step 2: Commit**

"feat: add EvernoteService create/update note and notebook resolution"

### Task 3.3: Sync job and model events

**Files:**
- Create: `app/Jobs/SyncThoughtToEvernote.php`
- Modify: `app/Models/Thought.php` (observer or boot)

- [ ] **Step 1:** Job: receives Thought; if evernote_note_guid null call create; else call update. On failure log and retry. Do not fail HTTP request.
- [ ] **Step 2:** On Thought::created and Thought::updated, dispatch SyncThoughtToEvernote. If Evernote not configured, skip dispatch.
- [ ] **Step 3: Commit**

"feat: queue Evernote sync on thought create/update"

### Task 3.4: README and docs

**Files:**
- Modify: `README.md` or `docs/`

- [ ] **Step 1:** Document Evernote mirror: env vars, notebook mapping, queued sync.
- [ ] **Step 2: Commit**

"docs: Evernote mirror setup"

---

## Execution notes

- **TDD:** Write failing tests for services and controllers where practical; then implement. Pest preferred per project.
- **DRY / YAGNI:** Reuse OpenRouterService for web capture; no extra idempotency keys for Evernote beyond evernote_note_guid.
- **Frequent commits:** One commit per task or per logical step.
- **Review:** After each chunk, run tests and smoke-check (MCP call, web search/capture).

---

## Summary checklist

- [ ] Phase 0: Thoughts table, Thought model, OpenRouterService, MCP (if not present); Slack out of scope
- [ ] Phase 1: user_id on thoughts, user_mcp_keys, MCP key → user, scope MCP by user
- [ ] Phase 2: Simple web GUI — IdeaController, GET / and POST /thoughts, Blade view (search, input, comments), auth
- [ ] Phase 3: evernote_note_guid, EvernoteService, SyncThoughtToEvernote job, model events

Plan complete. Ready to execute with subagent-driven-development or executing-plans.

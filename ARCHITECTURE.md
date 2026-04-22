# IdeaTub – Architecture Overview

IdeaTub is a **personal knowledge system** with semantic search and AI agent integration. Users capture thoughts from the web UI, AI agents (via MCP), email, Jira, or video transcripts; search them semantically; and optionally mirror them to Evernote.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.2+) |
| Database | PostgreSQL + pgvector (1536-dim embeddings) |
| Frontend | Blade + Tailwind CSS + Alpine.js |
| Build | Vite + npm |
| Embeddings & metadata extraction | OpenRouter API (server-side only) |
| Realtime | Laravel Reverb (WebSockets) or polling fallback |
| Auth | Laravel Breeze (web) + per-user MCP keys + OAuth 2.1 |
| Queues | Sync (default) or Redis |
| Payments | Laravel Cashier (Stripe) |
| External integrations | Evernote, Jira, Postmark (inbound email), Fastmail/JMAP |

---

## Project Structure

```
app/
  Console/          Artisan commands (e.g. ideatub:create-mcp-keys)
  Http/Controllers/ Web + API + MCP controllers
  Jobs/             Queued jobs (SyncThoughtToEvernote, JiraSync, …)
  Models/           Eloquent models (Thought, User, UserMcpKey, …)
  Services/         Business logic (ThoughtCaptureService, OpenRouterService, …)
  Support/          Utilities (SearchService, TagSlug, DemoMode, …)
decisions/          Architecture Decision Records (ADRs)
docs/               Integration guides, deployment notes, feature specs
resources/views/    Blade templates
resources/prompts/  Prompt files (research.md, overrideable via env)
routes/
  web.php           UI routes
  api.php           REST + MCP endpoint
tests/              Pest PHP test suite
```

---

## Key Architectural Decisions

### 1. User Isolation
Every thought is scoped to a `user_id`. MCP keys identify the user, not the agent — the same key is used across all AI clients. Multiple keys per user are supported for per-client revocation.

### 2. MCP Authentication
Keys are stored hashed in `user_mcp_keys`. Passed via `x-ideatub-key` header (preferred, avoids logs) or `?key=` query param. OAuth 2.1 bearer tokens are also accepted (for ChatGPT connector, enabled via `OAUTH_MCP_ENABLED`).

### 3. Unified Capture Pipeline
`ThoughtCaptureService` handles web form, MCP, and REST API captures consistently. Content >500 words auto-chunks at markdown headings into a root thought + section children. Comments (thoughts with `parent_id`) never chunk. The `no_chunking` flag disables auto-chunking.

### 4. Two-Phase Semantic Search
Search runs tag matching (exact, sorted by `created_at` DESC) then vector cosine similarity via pgvector (sorted by distance ASC). If no results fall within the 0.5 cosine threshold, the top-N by distance are returned as a fallback.

### 5. Thoughts Schema
Core table: `thoughts` (`id` UUID, `user_id`, `content`, `embedding` vector(1536), `metadata` JSON, `parent_id` UUID, `source`, `source_metadata` JSON).

`metadata` JSON structure:
```json
{
  "type": "idea|research|plan|meeting|task|note|video|…",
  "tags": ["tag1", "tag2"],
  "people": ["person1"],
  "action_items": ["…"],
  "completed": false,
  "completed_at": null,
  "logged_date": "YYYY-MM-DD"
}
```

### 6. Evernote Mirror (optional)
Postgres is the source of truth; Evernote is a read mirror only. Notebook routing is config-driven (type/tag → `notebook_guid`). Sync is idempotent via `evernote_note_guid`. Failures are queued and do not fail the original request.

### 7. Polymorphic Comments
`Comment` model (`commentable_*` polymorphic) replaces the old reply-shaped child-thought pattern. Old reply-shaped thoughts are flagged `metadata->migrated_to_comment = 'true'` and hidden via a global scope.

### 8. API Surface

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /api/mcp` | MCP key or OAuth bearer | JSON-RPC 2.0 tool calls |
| `GET /api/thoughts/search` | OAuth bearer | REST search |
| `GET /api/thoughts/recent` | OAuth bearer | REST recent |
| `POST /api/thoughts` | OAuth bearer | REST create |
| `POST /thoughts` | Session | Web form capture |
| `GET /stream` | Session | Tag-filtered stream view |

**MCP tools:** `capture_thought`, `capture_plan`, `search_thoughts`, `browse_recent`, `thought_stats`, `capture_meeting` (alias for `capture_plan` with `doc_type: meeting`), plus optional Jira/video/research tools.

### 9. Demo Mode
`DemoMode` detects demo accounts; `DemoObfuscator` replaces sensitive content with placeholder text in all output paths (web, research views, API).

### 10. Realtime Broadcasting
`ThoughtCreated` event broadcasts via Laravel Reverb when `REALTIME_DRIVER=reverb`. Polling is the default fallback. Streamed HTTP MCP sessions use `Mcp-Session-Id` headers with configurable TTL (`MCP_SESSION_TTL_SECONDS`).

---

## Key Environment Variables

| Variable | Purpose |
|---|---|
| `OPENROUTER_API_KEY` | Required — embeddings and metadata extraction |
| `DB_*` | PostgreSQL connection |
| `EVERNOTE_ACCESS_TOKEN` | Optional — enables Evernote mirror |
| `JIRA_ENABLED` | Toggle Jira integration |
| `MAIL_SYNC_ENABLED` | Toggle email syncing |
| `QUEUE_CONNECTION` | `sync` (default) or `redis` |
| `REALTIME_DRIVER` | `polling` (default) or `reverb` |
| `OAUTH_MCP_ENABLED` | Enable ChatGPT connector OAuth flow |
| `POSTMARK_INBOUND_WEBHOOK_SECRET` | Inbound email webhook |
| `RESEARCH_PROMPT_PATH` | Override path for the research agent prompt |

---

## Deployment

- Designed for **Laravel Cloud** (serverless Postgres + queues).
- Docker image included.
- Requires `pgvector` extension; see `docs/local-postgres-setup.md`.
- Generate MCP keys: `php artisan ideatub:create-mcp-keys`.
- Full deployment steps: `DEPLOY.md`.

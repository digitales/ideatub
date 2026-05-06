# IdeaTub

A Laravel app for your personal knowledge system: semantic search over thoughts, capture via web or MCP, and optional Evernote mirror. Part of the [Open Brain](https://promptkit.natebjones.com/20260224_uq1_guide_main) setup.

## Features

- **Capture:** Web form and MCP tool `capture_thought` (e.g. “remember this” in Cursor/Claude/ChatGPT).
- **Retrieval:** Semantic search and browse recent thoughts via web (`GET /?q=...`) or MCP tools (`search_thoughts`, `browse_recent`, `thought_stats`).
- **Storage:** PostgreSQL with pgvector; thoughts are embedded and stored per user.
- **Auth:** Per-user isolation; Laravel web login and per-user MCP keys.
- **Agent workflows:** **Panning for Gold** prompts under [`resources/prompts/panning-for-gold-*.md`](resources/prompts/) process meeting transcripts and brain dumps into inventory / gold-found markdown and IdeaTub MCP captures; see [design spec](docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md) and [`CLAUDE.md`](CLAUDE.md).

## Setup

### Prerequisites

- PHP 8.2+
- Composer
- Node.js & npm (for frontend assets)
- PostgreSQL with [pgvector](https://github.com/pgvector/pgvector) extension (see [Local PostgreSQL setup](docs/local-postgres-setup.md) for schema permission or “extension not available” errors)

### Install

1. Clone the repository and enter the project:

   ```bash
   git clone <repo-url> ideatub && cd ideatub
   ```

2. Install dependencies:

   ```bash
   composer install
   npm install && npm run build
   ```

3. Environment:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Configure `.env` (see [Configuration](#configuration)): at minimum set `OPENROUTER_API_KEY` and database (e.g. `DB_CONNECTION=pgsql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

5. Enable pgvector and run migrations:

   ```bash
   php artisan migrate
   ```

6. (Optional) Create users (register via web or tinker), then create per-user MCP keys:

   ```bash
   php artisan ideatub:create-mcp-keys
   ```

   Plain keys are shown once in the console; copy and store them securely. See [MCP URL and per-user key](#mcp-url-and-per-user-key).

7. Start the app:

   ```bash
   php artisan serve
   ```

   Visit `http://localhost:8000`. Log in (or register) to use the idea capture and search UI.

## Configuration

Do not commit `.env`; it is listed in `.gitignore` and is never sent to the client.

See `.env.example` for all variables. Key ones:

| Variable | Required | Description |
|----------|----------|-------------|
| `OPENROUTER_API_KEY` | Yes | OpenRouter API key for embeddings and metadata extraction. Server-only; never exposed to the client. |
| `MCP_ACCESS_KEY` | No (legacy) | Optional single shared key for MCP; prefer per-user keys from `ideatub:create-mcp-keys`. |
| `DB_CONNECTION` | Yes | e.g. `pgsql` (default). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Yes (for pgsql) | PostgreSQL connection. |
| `QUEUE_CONNECTION` | No | `sync` for no queue; `redis` (or similar) when using queued jobs (e.g. Evernote sync). |
| `EVERNOTE_ACCESS_TOKEN` | No | Evernote API token; if set, thoughts are mirrored to Evernote (see [Evernote mirror](docs/evernote-mirror.md)). |
| `EVERNOTE_NOTEBOOK_GUID_*` | No | Notebook GUIDs for mapping (e.g. `_DEFAULT`, `_IDEA`, `_TASK`). See [Evernote mirror](docs/evernote-mirror.md). |

## Evernote mirror

Thoughts can be mirrored to Evernote as notes. Set `EVERNOTE_ACCESS_TOKEN` (and optional `EVERNOTE_NOTEBOOK_GUID_*` vars) to enable. Sync runs via a queued job—use `php artisan queue:work` when `QUEUE_CONNECTION` is not `sync`. Full setup, notebook mapping (type/tags → notebooks), and env reference: [docs/evernote-mirror.md](docs/evernote-mirror.md).

## MCP URL and per-user key

- **Endpoint:** `POST https://your-app-domain/api/mcp` (or `http://localhost:8000/api/mcp` in development.) The same path supports **legacy JSON-RPC** and **MCP Streamable HTTP** (clients must send `Accept` with both `application/json` and `text/event-stream` for the latter). Details: [docs/mcp-integration-guide.md](docs/mcp-integration-guide.md).
- **Auth:** Send your **per-user MCP key** via header `x-ideatub-key: YOUR_KEY` (preferred), or OAuth Bearer after connector login. Query `?key=` is discouraged (prefer header so keys are less likely to appear in logs).
- **Getting a key:** Each user gets at least one key from:

  ```bash
  php artisan ideatub:create-mcp-keys
  ```

  Keys are shown once; store them securely. Use the same key in every AI client (Cursor, Claude, ChatGPT); it identifies the user, not the agent. See [dev/mcp-keys-implementation.md](dev/mcp-keys-implementation.md) for details.

**Connecting your AI client:** For step-by-step setup in Claude Desktop, ChatGPT, Cursor, Claude Code, and others, see **[docs/mcp-integration-guide.md](docs/mcp-integration-guide.md)**.

## Working memory retrieval

Use working memory when you need a current global, project, insights, or tag-scoped summary with freshness and confidence metadata.

- **MCP method:** `get_working_memory`
- **REST endpoint:** `GET /api/thoughts/working-memory`
- **Required scope params:** `scope_type` and `scope_key`

### Web UI and feature flags

When enabled, authenticated pages expose the same snapshot in the browser (baseline + incremental overlay details). Defaults are **off** until you set env vars on the server.

| Env variable | Config key | Default | Purpose |
|--------------|------------|---------|---------|
| `FEATURE_WORKING_MEMORY_UI` | `features.working_memory_ui` | `false` | Enables Memory nav, `/memory`, project memory module, and related UI. |
| `FEATURE_WORKING_MEMORY_INSIGHTS` | `features.working_memory_insights` | `false` | Enables `/memory/insights` (corpus research-heavy signals). |
| `WORKING_MEMORY_INSIGHTS_MODEL_ENABLED` | `working_memory.insights_model_enabled` | `false` | Allows optional LLM-backed insights paths when Insights is on (deployment choice). |

**Routes** (paths on your IdeaTub base URL):

- `/memory` — global working memory viewer
- `/memory/insights` — insights page (requires both UI and Insights flags where applicable)
- `/settings/working-memory` — per-user consolidation window override

Common scopes:

- `scope_type=global`, `scope_key=global`
- `scope_type=project`, `scope_key=my-app`
- `scope_type=insights`, `scope_key=global` — versioned research-heavy corpus summary (same persistence tables as other scopes)
- `scope_type=tag`, `scope_key=ai` — normalized tag scope (trimmed, lowercase key)

REST examples:

```bash
# Global scope
curl -H "Authorization: Bearer <OAUTH_TOKEN>" \
  "http://localhost:8000/api/thoughts/working-memory?scope_type=global&scope_key=global"

# Project scope
curl -H "Authorization: Bearer <OAUTH_TOKEN>" \
  "http://localhost:8000/api/thoughts/working-memory?scope_type=project&scope_key=my-app"

# Insights scope (research-classified captures)
curl -H "Authorization: Bearer <OAUTH_TOKEN>" \
  "http://localhost:8000/api/thoughts/working-memory?scope_type=insights&scope_key=global"

# Tag scope (normalized tag key)
curl -H "Authorization: Bearer <OAUTH_TOKEN>" \
  "http://localhost:8000/api/thoughts/working-memory?scope_type=tag&scope_key=ai"
```

MCP JSON-RPC example:

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "get_working_memory",
  "params": {
    "scope_type": "global",
    "scope_key": "global"
  }
}
```

### Cursor rule: sync plans and docs to IdeaTub

This repo includes a Cursor rule so that when you work with plan, decision, dev, support, or spec markdown files, the AI knows how to sync them to IdeaTub via **capture_plan** (correct `doc_type`, `file_path`, `plan_slug`). The rule lives in [.cursor/rules/ideatub-sync-docs.mdc](.cursor/rules/ideatub-sync-docs.mdc). To use it in another project, copy that `.mdc` file into that project’s `.cursor/rules/` and ensure IdeaTub MCP is configured there. See [.cursor/rules/README.md](.cursor/rules/README.md) for details. You can also **download the rule** from the in-app **Help** page (MCP section).

**Panning for Gold:** [.cursor/rules/panning-for-gold.mdc](.cursor/rules/panning-for-gold.mdc) applies when working under `docs/brainstorming/` or the panning prompt files; it routes the agent to the meeting vs brain-dump wrappers and shared core prompt.

## Web login

- Use Laravel’s built-in auth: register at `/register`, log in at `/login`.
- After login, the home page (`/`) is the IdeaTub UI: search box, capture form, and recent thoughts. All data is scoped to the logged-in user.

## Companion Prompt Kit

IdeaTub is designed to work with the [Open Brain Companion Prompts](https://promptkit.natebjones.com/20260224_uq1_promptkit_1):

- **Prompts 1 (Memory Migration), 2 (Second Brain Migration), 5 (Weekly Review):** Require the Open Brain MCP server. Point your AI client at the MCP URL above and use your per-user key; the AI will call `capture_thought`, `search_thoughts`, `browse_recent`, and `thought_stats` as needed.
- **Prompt 4 (Quick Capture Templates):** Use the same templates in the web form or in chat (“save this”); metadata (type, tags, people, action items) is extracted the same way.

## Tech stack

- **Backend:** Laravel 12, PostgreSQL, pgvector
- **Frontend:** Blade, Tailwind CSS
- **AI:** OpenRouter (embeddings + metadata)
- **Queues:** Optional (e.g. Redis) for Evernote sync

## License

MIT

# Integrating IdeaTub as MCP

This guide explains how to connect your AI assistant (Cursor, Claude Desktop, ChatGPT, Claude Code, or other MCP-capable clients) to IdeaTub so it can search your thoughts and capture new ones.

## Overview

IdeaTub exposes **`POST /api/mcp`** with **JSON-RPC 2.0** payloads for thought capture/search tools, meeting/research tools, and helper aliases. The same URL supports two transports:

| Transport | When it applies |
|-----------|-----------------|
| **Legacy JSON-RPC** | Typical clients send `Accept: application/json` (no `text/event-stream`). Single-request JSON in, JSON out. |
| **MCP Streamable HTTP** ([spec 2025-03-26](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports)) | Clients send **`Accept: application/json, text/event-stream`** (both parts required). Uses `initialize` → **`Mcp-Session-Id`** on responses, then `notifications/initialized` (202), then `tools/list`, `tools/call`, and JSON-RPC tool methods. |

See [Protocol note](#protocol-note) for Origin allowlisting, OAuth, and limitations.

### Agent workflows (Panning for Gold)

This repo ships **Panning for Gold** prompts (`resources/prompts/panning-for-gold-core.md` plus meeting vs brain-dump wrappers) so agents with IdeaTub MCP can extract threads from transcripts or dumps, synthesise a gold-found markdown file, then call **`capture_plan`** / **`capture_meeting`** / **`capture_thought`**. Design: `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md`. Upstream methodology: [OB1 — panning-for-gold](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold). **In-app:** browse or download the prompts from Help → **Panning for Gold** (URL path `/help/panning-for-gold` on your IdeaTub instance; ZIP or individual `.md` files).

### Agent workflows (Research-to-Decision)

The **[OB1 Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow)** chains five skills (competitive analysis, optional financial model review, research synthesis, meeting synthesis, optional deal memo). This repository ships **`resources/skills/research-to-decision/*/SKILL.md`** with IdeaTub MCP and Help URLs prefilled (`https://ideatub.com/...`); copy into your agent or compare with [OB1 `skills/`](https://github.com/NateBJones-Projects/OB1/tree/main/skills). **`resources/prompts/research-to-decision-ideatub.md`** documents `docs/research-to-decision/` paths, **`plan_slug` / `doc_type`**, and **`search_thoughts`** priming. Workspace layout: `docs/research-to-decision/README.md`. Design: `docs/superpowers/specs/2026-04-30-research-to-decision-ideatub-design.md`. In-app: Help → **Research-to-decision workflow** (`/help/research-to-decision`).

Tools available:

| Tool | Description |
|------|-------------|
| `search_thoughts` | Semantic search over your captured thoughts |
| `browse_recent` | List recent thoughts (optional limit) |
| `thought_stats` | Count of your thoughts |
| `capture_thought` | Save a new thought (and optionally a comment on an existing one) |
| `capture_plan` | Save a plan or plan section with source=plan; use tags and optional parent_id for long-form linking |
| `capture_meeting` / `add_meeting` / `add_meeting_notes` | Meeting aliases for `capture_plan` with `doc_type=meeting` |
| `process_meeting` | Queue meeting summarization + categorization (from existing meeting thought or raw transcript content) |
| `get_working_memory` | Return a global or project scoped working memory snapshot (`scope_type`, `scope_key`; same payload shape as `GET /api/thoughts/working-memory`, including assembler metadata fields in [Get working memory response shape](#get-working-memory-response-shape)) |

Authentication is either **per-user MCP key** (`x-ideatub-key` header; query `?key=` is discouraged—prefer header) or **OAuth** (`Authorization: Bearer` after connector login). The MCP key identifies **your user account**, not the app.

---

## Prerequisites

1. **IdeaTub account** — Register and log in at your IdeaTub instance (e.g. `https://your-ideatub.com`).
2. **MCP key** — You need at least one per-user MCP key. See [Getting your MCP key](#getting-your-mcp-key).

---

## Getting your MCP key

### Option A: From the IdeaTub web app (recommended)

1. Log in to IdeaTub.
2. Open your profile menu (avatar, top right) and click **MCP key** (or go to **Settings → MCP key**).
3. Click **Create MCP key**. Your new key is shown **once** — copy it immediately and store it securely (e.g. in a password manager).
4. Use the same key in every AI client (Cursor, Claude, ChatGPT). You can create additional keys if needed (e.g. one per device) and revoke any key from that page.

### Option B: Administrator creates keys (CLI)

Someone with server access can create keys for users:

```bash
php artisan ideatub:create-mcp-keys
```

For each user, the command prints a key **once** (e.g. `ideatub_abc123...`). To create an extra key for an existing user:

```bash
php artisan ideatub:create-mcp-keys --force
```

### Option C: You have server access

If you deploy IdeaTub yourself, you can use either the web app (Option A) or the CLI (Option B) to create your key.

---

## MCP connection URL and auth

- **Endpoint:** `https://your-ideatub.com/api/mcp`  
  (In development: `http://localhost:8000/api/mcp`.)

- **Auth (choose one):**
  - **Query parameter:** `?key=YOUR_MCP_KEY`
  - **Header (preferred):** `x-ideatub-key: YOUR_MCP_KEY`  

  Using the header avoids the key appearing in server or proxy logs.

**Full URL with key in query (for clients that only support URL):**

```
https://your-ideatub.com/api/mcp?key=ideatub_your_32_char_key_here
```

Use the **same key** in every AI client (Cursor, Claude, ChatGPT, etc.). It always refers to your user account.

---

## Client-specific setup

### Claude Desktop

1. Open **Settings** → **Connectors**.
2. Click **Add custom connector**.
3. **Name:** e.g. `IdeaTub`
4. **Remote MCP server URL:** your IdeaTub MCP URL.  
   If Claude supports a URL with a query string, use:  
   `https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY`  
   If it only accepts a base URL, use the base URL and add the key as a custom header if the UI allows (e.g. `x-ideatub-key: YOUR_MCP_KEY`).
5. Save and start a new conversation. Enable the IdeaTub connector via the "+" → Connectors menu.

If tools do not appear, ensure the client uses **MCP Streamable HTTP** (`Accept` includes both `application/json` and `text/event-stream`) or legacy JSON-RPC per [Protocol note](#protocol-note). For blocked browser `Origin` hosts, set **`MCP_STREAMABLE_ALLOWED_HOSTS`** (comma-separated hostnames) on the server.

---

### ChatGPT (web, paid plans)

1. Go to **Settings** → **Apps & Connectors** → **Advanced settings** and turn **Developer mode** ON (required for custom MCP).
2. In **Apps & Connectors**, click **Create**.
3. **Name:** e.g. `IdeaTub`
4. **MCP endpoint URL:** `https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY`
5. **Authentication:** No Authentication (key is in the URL).
6. Create and start a new chat with the IdeaTub connector enabled.

If ChatGPT does not call the tools automatically, try: “Use the IdeaTub search_thoughts tool to find my notes about …”.

---

### Cursor

1. Open **Cursor Settings** (e.g. **Cmd+,** / **Ctrl+,**) → **Tools & MCP**.
2. Click **Add new MCP server**.
3. If there is a **URL** or **Remote** type (e.g. `streamableHttp`), enter:  
   `https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY`  
   If the UI allows custom headers, you can use the base URL and set `x-ideatub-key` to your key.
4. Save and restart Cursor if needed.

Cursor **remote / streamable HTTP** connectors should use the base URL with **`Accept`** listing **both** JSON and **SSE** as above, plus your key header if required. If tools do not show up, see [Protocol note](#protocol-note) (Origin allowlist, session headers) and the [JSON-RPC API reference](#json-rpc-api-reference) for scripting or bridges.

**See also:** [Cursor MCP integration](cursor-mcp-integration.md) — setup and using `capture_thought` from Cursor.

---

### Claude Code (Claude CLI)

If your Claude Code build supports remote MCP over HTTP:

```bash
claude mcp add --transport http ideatub \
  https://your-ideatub.com/api/mcp \
  --header "x-ideatub-key: YOUR_MCP_KEY"
```

Use your real key in place of `YOUR_MCP_KEY`. If your client only supports URL-based key, use the full URL with `?key=...` if the tool allows.

---

### Other clients (VS Code Copilot, Windsurf, etc.)

- **If the client has “Remote MCP server URL” or “Custom connector URL”:**  
  Use `https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY`.

- **If the client only supports local (stdio) MCP servers:**  
  You need a small **bridge** that:
  - Listens as a local MCP server (stdio or HTTP).
  - Forwards tool calls to IdeaTub’s JSON-RPC endpoint with your key (query or `x-ideatub-key` header).

  The [JSON-RPC API reference](#json-rpc-api-reference) below gives the exact request/response format for building such a bridge.

---

## Protocol note

### JSON-RPC shape (both transports)

Requests and responses use **JSON-RPC 2.0**. Tool-style methods (`search_thoughts`, `browse_recent`, etc.) and MCP methods (`initialize`, `tools/list`, `tools/call`) share the same JSON-RPC envelope; the server maps `tools/call` to those tools.

### Legacy JSON-RPC (simple HTTP clients)

Send **`POST /api/mcp`** with **`Content-Type: application/json`** and an **`Accept`** header that does **not** imply Streamable HTTP—typically `Accept: application/json` only. Authenticate with **`x-ideatub-key`** or **`Authorization: Bearer`** (OAuth). No session header is required.

### MCP Streamable HTTP (remote connectors, Claude/Cursor-class clients)

Implemented on the **same** URL: **`POST /api/mcp`**. The client must send **`Accept`** including **both** `application/json` and `text/event-stream` (per spec). Flow:

1. **`initialize`** — response includes **`Mcp-Session-Id`** (new session).
2. **`notifications/initialized`** — notification only; server returns **202** with empty body.
3. Subsequent requests include **`Mcp-Session-Id`** matching that session (except `initialize`).

**Origin:** For Streamable requests that include an **`Origin`** header, the host must match an allowlist (defaults include Claude, ChatGPT, Cursor, and your **`APP_URL`** host). Add extra hosts via env **`MCP_STREAMABLE_ALLOWED_HOSTS`** (comma-separated), e.g. a connector preview domain.

**OAuth:** Works with Streamable HTTP the same way as legacy (Bearer token after authorization).

**Not implemented or partial (check client compatibility):**

- **`GET /api/mcp`** with `Accept: text/event-stream` returns **405** (reserved; SSE GET not offered yet).
- **Batched** JSON-RPC arrays in one POST body are rejected in Streamable mode (`400`).
- Responses are primarily **`application/json`** bodies; some clients expect SSE-framed bodies for every message—if a client still fails after correct `Accept` and session headers, a thin **adapter** may be needed. The [JSON-RPC API reference](#json-rpc-api-reference) is enough to build one.

Implementation reference: `App\Http\Controllers\Api\McpController`, `config/mcp.php`, tests `tests/Feature/McpStreamableHttpTest.php`.

---

## JSON-RPC API reference

Use this if you are scripting against IdeaTub or building a bridge.

**Request:** `POST /api/mcp`  
**Headers:** `Content-Type: application/json` and either `x-ideatub-key: YOUR_MCP_KEY` or use `?key=YOUR_MCP_KEY`.  
**Body (JSON-RPC 2.0):**

```json
{
  "jsonrpc": "2.0",
  "method": "search_thoughts",
  "params": { "query": "career changes", "limit": 10 },
  "id": 1
}
```

**Success response:** Each thought in `search_thoughts` and `browse_recent` includes `source` and `source_metadata` (both may be null for older thoughts).

```json
{
  "jsonrpc": "2.0",
  "result": { "thoughts": [ { "id": "...", "content": "...", "metadata": {...}, "created_at": "...", "source": "mcp", "source_metadata": null } ] },
  "id": 1
}
```

**Error response (e.g. invalid key):** HTTP 401 and body like:

```json
{
  "jsonrpc": "2.0",
  "error": { "code": -32001, "message": "Unauthorized: invalid or missing MCP key" },
  "id": null
}
```

### Tool parameters

| Method | Required params | Optional params |
|--------|-----------------|-----------------|
| `search_thoughts` | `query` (string) | `limit` (int, default 10, max 100) |
| `browse_recent` | — | `limit` (int, default 10, max 100) |
| `thought_stats` | — | — |
| `capture_thought` | `content` (string) | `parent_id` or `in_reply_to` (UUID); `source` (string, e.g. chatgpt/claude/cursor); `source_metadata` (object) |
| `capture_plan` | `content` (string) | `doc_type` (plan \| decision \| dev \| support \| spec \| research \| meeting); `file_path`, `plan_slug`, `parent_id` (UUID), `section_title`, `project`, `tags` (array) |
| `capture_meeting`, `add_meeting`, `add_meeting_notes` | `content` (string) | Same optional params as `capture_plan` **except** `doc_type` is omitted and always `meeting`. These three names are **aliases** of one implementation (any `doc_type` in params is ignored). |
| `process_meeting` | One of `thought_id` (UUID) or `content` (string) | `plan_slug` (when `content` is provided), `meeting_skill_id` (int), `force_rerun` (bool) |
| `get_working_memory` | `scope_type` (`global` \| `project`), `scope_key` (string, required; e.g. `global` for global scope, or project id / normalized project slug for project scope) | — |

Example calls:

```json
{"jsonrpc":"2.0","method":"search_thoughts","params":{"query":"meeting notes","limit":5},"id":1}
{"jsonrpc":"2.0","method":"browse_recent","params":{"limit":20},"id":2}
{"jsonrpc":"2.0","method":"thought_stats","params":{},"id":3}
{"jsonrpc":"2.0","method":"capture_thought","params":{"content":"Decided to move the launch to March 15."},"id":4}
{"jsonrpc":"2.0","method":"capture_thought","params":{"content":"Done.","parent_id":"uuid-of-parent-thought"},"id":5}
{"jsonrpc":"2.0","method":"capture_plan","params":{"content":"## Chunk 1: Phase 0...","file_path":"docs/superpowers/plans/2026-03-12-tag-and-stream.md","plan_slug":"2026-03-12-tag-and-stream","section_title":"Chunk 1"},"id":6}
{"jsonrpc":"2.0","method":"add_meeting_notes","params":{"content":"## Standup\n\n- Shipped search","plan_slug":"2026-04-02-standup"},"id":7}
{"jsonrpc":"2.0","method":"process_meeting","params":{"thought_id":"uuid-of-existing-meeting"},"id":8}
{"jsonrpc":"2.0","method":"process_meeting","params":{"content":"Speaker A: ...","plan_slug":"2026-04-15-weekly-sync"},"id":9}
{"jsonrpc":"2.0","method":"get_working_memory","params":{"scope_type":"global","scope_key":"global"},"id":10}
```

#### Get working memory response shape

Successful `get_working_memory` and **`GET /api/thoughts/working-memory`** return the same JSON object. Beyond legacy fields (markdown body, scope, timestamps, confidence, etc.), the assembler adds non-breaking keys for clients and UI:

| Field | Description |
|-------|-------------|
| `last_refreshed_at` | ISO 8601 timestamp when the snapshot was last refreshed (or `null`). |
| `effective_consolidation_window_days` | Resolved window (user preference capped to valid range, else config default). |
| `baseline_build_type` | How the canonical consolidated baseline was produced (string). |
| `overlay_deltas` | Structured list of incremental changes since consolidation (each item typically includes `label`, `detail`, `since`). |
| `input_count` | Number of inputs contributing to the canonical baseline. |

For more on `capture_thought` and comments, see [MCP capture_thought](mcp-capture-thought.md).

### Plans and documents as thoughts (`capture_plan`)

Use **`capture_plan`** when syncing plans, decisions, dev notes, support docs, specs, research, or meeting notes into IdeaTub. Set **`doc_type`** to one of: `plan`, `decision`, `dev`, `support`, `spec`, `research`, `meeting` (default `plan`). The source and tag prefix match (e.g. `decision:project-spec`, `research:2026-03-13-vehicle-valuation`, `meeting:2026-04-01-standup`). Supported paths: `docs/superpowers/plans/*.md`, `decisions/*.md`, `dev/*.md`, `support/*.md`, `specs/*.md`; for research or meetings use any logical path or omit. Use **`project`** to record which code project or research topic the content belongs to. Meeting notes also appear under **Stream → Meetings** in the web app when captured with `doc_type: meeting`.

**Meeting shortcuts:** JSON-RPC methods **`capture_meeting`**, **`add_meeting`**, and **`add_meeting_notes`** are aliases: same behavior as `capture_plan` with `doc_type` fixed to `meeting` (and the same optional fields as `capture_plan` except `doc_type` is not used). MCP **`tools/list`** exposes all three so clients can pick a natural name.

- **One thought per section:** Send one `capture_plan` per section. Use the same **`plan_slug`** for all sections. IdeaTub adds a tag `<doc_type>:<slug>` (e.g. `decision:project-spec`). View in Stream via `/stream?tag=decision-project-spec` etc.
- **Long-form view via Stream:** Open **Stream** and filter by the tag using the URL slug form (e.g. `/stream?tag=decision-project-spec`).
- **Linking sections to a root (optional):** Create a root thought first, then for each section set **`parent_id`** to the root's UUID.
- **Document link:** Pass **`file_path`** (e.g. `decisions/project-spec.md`, `support/example-investigation.md`) so `source_metadata` records the source file.

---

## Troubleshooting

| Issue | What to check |
|-------|----------------|
| **401 Unauthorized** | Key or OAuth token missing/invalid. Prefer **`x-ideatub-key`** (or Bearer after OAuth); ensure no extra spaces. Key is per-user and shown only once when created. |
| **Tools don’t appear** | Confirm **`Accept`** for Streamable HTTP includes **both** `application/json` and `text/event-stream`; send **`Mcp-Session-Id`** after `initialize`. Check **`Origin`** against [Protocol note](#protocol-note) allowlist (`MCP_STREAMABLE_ALLOWED_HOSTS`). If the client requires SSE-framed responses only, use or build a bridge ([JSON-RPC API reference](#json-rpc-api-reference)). |
| **Search returns nothing** | Capture some thoughts first (web UI or `capture_thought`). Ensure you’re using the key for the user who owns those thoughts. |
| **Key lost** | Keys are stored hashed; the plain key cannot be retrieved. An admin must create a new key with `ideatub:create-mcp-keys --force` for your user; use the new key everywhere. |

---

## Summary

1. Get your **MCP key** (from an admin or `php artisan ideatub:create-mcp-keys`).
2. Build the **connection URL**: `https://your-ideatub.com/api/mcp` with **`x-ideatub-key`** (preferred), OAuth, or legacy `?key=` if the client cannot send headers. For **Streamable HTTP**, ensure **`Accept`** lists both JSON and **`text/event-stream`** ([Protocol note](#protocol-note)).
3. Add IdeaTub as a **custom/remote MCP connector** in your AI client using that URL (and headers your client expects).
4. Use the same key in every client; it always means “this user’s IdeaTub brain.”

For prompt ideas (memory migration, second brain migration, weekly review), see the [Companion Prompt Kit](https://promptkit.natebjones.com/20260224_uq1_promptkit_1) and the example prompts in `resources/content/example-prompts/`.

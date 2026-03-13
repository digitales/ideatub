# Integrating IdeaTub as MCP

This guide explains how to connect your AI assistant (Cursor, Claude Desktop, ChatGPT, Claude Code, or other MCP-capable clients) to IdeaTub so it can search your thoughts and capture new ones.

## Overview

IdeaTub exposes an **MCP-style API** at `POST /api/mcp`. It uses **JSON-RPC 2.0** with five tools:

| Tool | Description |
|------|-------------|
| `search_thoughts` | Semantic search over your captured thoughts |
| `browse_recent` | List recent thoughts (optional limit) |
| `thought_stats` | Count of your thoughts |
| `capture_thought` | Save a new thought (and optionally a comment on an existing one) |
| `capture_plan` | Save a plan or plan section with source=plan; use tags and optional parent_id for long-form linking |

Authentication is by **per-user MCP key**: you send your key via query parameter or header. The same key works in every client; it identifies **you**, not the app.

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

If tools do not appear, Claude may expect the standard MCP HTTP/SSE transport. IdeaTub uses JSON-RPC; see [Protocol note](#protocol-note) and consider using a small bridge.

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

Cursor’s MCP layer may expect the official MCP Streamable HTTP transport. IdeaTub speaks JSON-RPC; if tools do not show up, see [Protocol note](#protocol-note) and the [JSON-RPC API reference](#json-rpc-api-reference) to use or build a bridge.

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

IdeaTub’s `/api/mcp` endpoint uses **JSON-RPC 2.0** with method names that match the Open Brain tool set (`search_thoughts`, `browse_recent`, `thought_stats`, `capture_thought`). It does **not** speak the official MCP wire protocol (e.g. Streamable HTTP / SSE used by some clients).

- Clients that accept a “custom HTTP connector” or “generic JSON-RPC” and let you set URL + headers may work with IdeaTub directly.
- Clients that only support the standard MCP transport may need a local or hosted **adapter** that translates MCP ↔ IdeaTub JSON-RPC. The API reference below is enough to implement that.

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
| `capture_plan` | `content` (string) | `doc_type` (plan \| decision \| dev \| support \| spec); `file_path`, `plan_slug`, `parent_id` (UUID), `section_title`, `tags` (array) |

Example calls:

```json
{"jsonrpc":"2.0","method":"search_thoughts","params":{"query":"meeting notes","limit":5},"id":1}
{"jsonrpc":"2.0","method":"browse_recent","params":{"limit":20},"id":2}
{"jsonrpc":"2.0","method":"thought_stats","params":{},"id":3}
{"jsonrpc":"2.0","method":"capture_thought","params":{"content":"Decided to move the launch to March 15."},"id":4}
{"jsonrpc":"2.0","method":"capture_thought","params":{"content":"Done.","parent_id":"uuid-of-parent-thought"},"id":5}
{"jsonrpc":"2.0","method":"capture_plan","params":{"content":"## Chunk 1: Phase 0...","file_path":"docs/superpowers/plans/2026-03-12-tag-and-stream.md","plan_slug":"2026-03-12-tag-and-stream","section_title":"Chunk 1"},"id":6}
```

For more on `capture_thought` and comments, see [MCP capture_thought](mcp-capture-thought.md).

### Plans and documents as thoughts (`capture_plan`)

Use **`capture_plan`** when syncing plans, decisions, dev notes, support docs, or specs into IdeaTub. Set **`doc_type`** to one of: `plan`, `decision`, `dev`, `support`, `spec` (default `plan`). The source and tag prefix match (e.g. `decision:project-spec`). Supported paths: `docs/superpowers/plans/*.md`, `decisions/*.md`, `dev/*.md`, `support/*.md`, `specs/*.md`.

- **One thought per section:** Send one `capture_plan` per section. Use the same **`plan_slug`** for all sections. IdeaTub adds a tag `<doc_type>:<slug>` (e.g. `decision:project-spec`). View in Stream via `/stream?tag=decision-project-spec` etc.
- **Long-form view via Stream:** Open **Stream** and filter by the tag using the URL slug form (e.g. `/stream?tag=decision-project-spec`).
- **Linking sections to a root (optional):** Create a root thought first, then for each section set **`parent_id`** to the root's UUID.
- **Document link:** Pass **`file_path`** (e.g. `decisions/project-spec.md`, `support/example-investigation.md`) so `source_metadata` records the source file.

---

## Troubleshooting

| Issue | What to check |
|-------|----------------|
| **401 Unauthorized** | Key is wrong or missing. Use `?key=...` or `x-ideatub-key`; ensure no extra spaces. Key is per-user and shown only once when created. |
| **Tools don’t appear** | Client may expect standard MCP transport. Try the URL with key; if it still fails, use or build a bridge (see [Protocol note](#protocol-note) and [JSON-RPC API reference](#json-rpc-api-reference)). |
| **Search returns nothing** | Capture some thoughts first (web UI or `capture_thought`). Ensure you’re using the key for the user who owns those thoughts. |
| **Key lost** | Keys are stored hashed; the plain key cannot be retrieved. An admin must create a new key with `ideatub:create-mcp-keys --force` for your user; use the new key everywhere. |

---

## Summary

1. Get your **MCP key** (from an admin or `php artisan ideatub:create-mcp-keys`).
2. Build the **connection URL**: `https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY` (or use base URL + `x-ideatub-key` header if the client supports it).
3. Add IdeaTub as a **custom/remote MCP connector** in your AI client using that URL (and header if needed).
4. Use the same key in every client; it always means “this user’s IdeaTub brain.”

For prompt ideas (memory migration, second brain migration, weekly review), see the [Companion Prompt Kit](https://promptkit.natebjones.com/20260224_uq1_promptkit_1) and the example prompts in `resources/content/example-prompts/`.

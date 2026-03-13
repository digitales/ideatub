# Cursor MCP integration (IdeaTub)

How to connect Cursor to IdeaTub so you can **capture thoughts** (and search/browse) from chat. When you say things like “remember this” or “save this for later,” Cursor can call the IdeaTub MCP tool `capture_thought`. To sync plan documents (e.g. `.cursor` plans or `docs/superpowers/plans/*.md`) into IdeaTub, use `capture_plan`.

## Setup in Cursor

1. **Get your MCP key**  
   In IdeaTub: profile menu → **MCP key** (or Settings → MCP key) → **Create MCP key**. Copy the key once and store it securely.

2. **Add IdeaTub as an MCP server in Cursor**
   - Open **Cursor Settings** (e.g. **Cmd+,** / **Ctrl+,**) → **Tools & MCP**.
   - Click **Add new MCP server**.
   - If Cursor offers a **URL** or **Remote** type (e.g. `streamableHttp`), use:
     ```text
     https://your-ideatub.com/api/mcp?key=YOUR_MCP_KEY
     ```
     Replace `your-ideatub.com` with your instance (e.g. `ideatub.example.com`) and `YOUR_MCP_KEY` with your key.
   - If the UI allows custom headers, you can use the base URL `https://your-ideatub.com/api/mcp` and set header `x-ideatub-key` to your key (keeps the key out of logs).
   - Save and restart Cursor if needed.

3. **Verify tools**  
   In a new chat, check that IdeaTub tools are available (e.g. `capture_thought`, `capture_plan`, `search_thoughts`, `browse_recent`, `thought_stats`). If they don’t appear, Cursor may expect the standard MCP transport; see [Protocol note](#protocol-note) in the main [MCP integration guide](mcp-integration-guide.md).

## Using capture_thought from Cursor

The **capture_thought** tool saves a new thought to your IdeaTub brain. Cursor can call it when you ask to remember something.

- **Simple capture:**  
  e.g. “Remember this: we’re shipping the feature on March 15.”  
  → Cursor can call `capture_thought` with `content` set to that text.

- **Optional source:**  
  The tool accepts optional `source` (e.g. `"cursor"`) so thoughts are tagged by client. If you don’t pass it, IdeaTub stores `source` as `mcp`.

- **Comments (replies):**  
  To attach a thought as a comment to an existing one, pass `parent_id` (or `in_reply_to`) with the parent thought’s UUID. See [MCP capture_thought](mcp-capture-thought.md).

**Parameters:**

| Parameter          | Required | Description |
|--------------------|----------|-------------|
| `content`          | Yes      | The thought or comment text. |
| `parent_id`        | No       | UUID of an existing thought (saves as comment). |
| `in_reply_to`      | No       | Alias for `parent_id`. |
| `source`           | No       | Client label (e.g. `cursor`). Default `mcp`. |
| `source_metadata`  | No       | Optional key-value object. |

## Using capture_plan from Cursor (plans as thoughts)

The **capture_plan** tool saves a plan or plan section with `source=plan`. Use it to sync Cursor/superpowers plan files into IdeaTub so they are searchable and viewable as a long-form stream.

- **One thought per section:** Call `capture_plan` once per section or chunk. Use the same **`plan_slug`** for all sections (e.g. `2026-03-12-tag-and-stream`). IdeaTub adds tag `plan:<slug>` so you can view all sections together in IdeaTub **Stream** by filtering on that tag (e.g. `/stream?tag=plan-2026-03-12-tag-and-stream`).
- **Linking via tags and parent:** Use **`plan_slug`** for tag-based grouping. Optionally create a plan root thought first, then pass its UUID as **`parent_id`** for section thoughts to link them in a hierarchy.
- **Document link:** Pass **`file_path`** (e.g. `docs/superpowers/plans/2026-03-12-tag-and-stream.md`) so the thought’s `source_metadata` records the source file.

**Parameters:**

| Parameter        | Required | Description |
|------------------|----------|-------------|
| `content`        | Yes      | Plan content (full plan or one section). |
| `file_path`      | No       | Path to the plan file. |
| `plan_slug`      | No       | Slug for this plan; adds tag `plan:<slug>` for Stream filtering. |
| `parent_id`      | No       | UUID of plan root thought to attach this section to. |
| `section_title` | No       | Title of this section (stored in source_metadata). |
| `tags`           | No       | Extra tags to merge with extracted and plan tag. |

## Protocol note

IdeaTub’s `/api/mcp` endpoint uses **JSON-RPC 2.0**, not the official MCP Streamable HTTP transport. If Cursor only supports standard MCP and tools don’t show up, you may need an adapter that translates MCP ↔ IdeaTub JSON-RPC; the [JSON-RPC API reference](mcp-integration-guide.md#json-rpc-api-reference) has the request/response format.

## More

- Full setup (all clients, keys, troubleshooting): [MCP integration guide](mcp-integration-guide.md)
- capture_thought parameters and comments: [MCP capture_thought](mcp-capture-thought.md)

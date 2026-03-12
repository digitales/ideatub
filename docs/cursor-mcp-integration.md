# Cursor MCP integration (IdeaTub)

How to connect Cursor to IdeaTub so you can **capture thoughts** (and search/browse) from chat. When you say things like “remember this” or “save this for later,” Cursor can call the IdeaTub MCP tool `capture_thought`.

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
   In a new chat, check that IdeaTub tools are available (e.g. `capture_thought`, `search_thoughts`, `browse_recent`, `thought_stats`). If they don’t appear, Cursor may expect the standard MCP transport; see [Protocol note](#protocol-note) in the main [MCP integration guide](mcp-integration-guide.md).

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

## Protocol note

IdeaTub’s `/api/mcp` endpoint uses **JSON-RPC 2.0**, not the official MCP Streamable HTTP transport. If Cursor only supports standard MCP and tools don’t show up, you may need an adapter that translates MCP ↔ IdeaTub JSON-RPC; the [JSON-RPC API reference](mcp-integration-guide.md#json-rpc-api-reference) has the request/response format.

## More

- Full setup (all clients, keys, troubleshooting): [MCP integration guide](mcp-integration-guide.md)
- capture_thought parameters and comments: [MCP capture_thought](mcp-capture-thought.md)

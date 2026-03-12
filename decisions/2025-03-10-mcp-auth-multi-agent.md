# MCP auth and multiple AI agents

> **Superseded:** This content is consolidated into [project-spec.md](./project-spec.md) (§3.1). Kept for reference only.

**Date**: 2025-03-10  
**Status**: Superseded  
**Context**: How authentication works for MCP when each user is isolated and may use several AI agents (Cursor, Claude, ChatGPT, etc.).

---

## Model

- **User** = one human (one IdeaTub account). Their thoughts are isolated from other users.
- **MCP key** = identifies the **user**, not the AI agent. One user can have one or more MCP keys (e.g. one key they paste into every client, or separate keys per client for revoking).
- **AI agent** = the MCP client (Cursor, Claude desktop, ChatGPT with MCP, etc.). The agent is not a first-class identity; it’s just the tool caller. All authorization is “which user does this key belong to?”

---

## How it works

1. **Per-user MCP keys**  
   Each user has at least one MCP access key (e.g. generated in the web UI or via CLI, stored hashed). The key is a long, opaque secret (e.g. `ideatub_...` or UUID).

2. **Same key in every agent**  
   The user configures **the same key** in each AI product they use:
   - **Cursor**: MCP server URL + key (e.g. query param or header).
   - **Claude (desktop / API)**: Same MCP server URL + same key.
   - **ChatGPT (with MCP)**: Same MCP server URL + same key.

3. **Request flow**  
   - Request hits `POST /api/mcp` with `?key=USER_MCP_KEY` or `x-ideatub-key: USER_MCP_KEY`.
   - Backend resolves key → **User** (e.g. `User` model, or lookup table `mcp_keys`: `key_hash`, `user_id`).
   - All MCP tools (`search_thoughts`, `browse_recent`, `thought_stats`, `capture_thought`) run in that user’s context: filter and write to that user’s thoughts only.

4. **No “per-agent” auth**  
   We do **not** require a different key per AI agent. The same key in Cursor and in ChatGPT means “same user, same thought store.” Optionally you can later add **per-key labels** (e.g. “Cursor”, “Claude”) for audit or display only, without changing auth.

---

## Optional: multiple keys per user

- **Single key:** One MCP key per user; they paste it everywhere. Simple; revoking logs out every client.
- **Multiple keys per user:** User can create several MCP keys (e.g. “Cursor”, “Claude”, “ChatGPT”). Same permissions (all see that user’s thoughts), but:
  - Revoke one key without affecting others.
  - Optional: store `label` or `client_id` per key for “which client did this?” in logs or UI.

Implementation: table `user_mcp_keys` (or similar) with `user_id`, `key_hash`, optional `label`, `last_used_at`; resolve key to user via this table.

---

## Summary

| Question | Answer |
|----------|--------|
| Who does the key identify? | The **user** (human account). |
| Do different AI agents need different keys? | No. Same key in Cursor, Claude, ChatGPT = same user, same data. |
| Can one user have multiple keys? | Optional; useful for revoking per client or labelling. |
| Where is the key sent? | Query param `?key=...` or header `x-ideatub-key`. |

All MCP tools are **user-scoped**: key → user → filter/write thoughts by `user_id`. The “agent” (Cursor vs Claude vs ChatGPT) is irrelevant to auth; it’s just which client is calling the tools.

# MCP capture_thought tool

The `capture_thought` tool creates a new thought in IdeaTub. It can also create a **comment** (reply) on an existing thought.

## Parameters

- **content** (required): The thought or comment text.
- **parent_id** (optional): ID of an existing thought. If provided, the new thought is stored as a comment on that thought (same user only).
- **in_reply_to** (optional): Alias for `parent_id`.

## Comment-on-thought

To attach a new thought as a comment to an existing one, pass `parent_id` (or `in_reply_to`) with the target thought’s ID. The parent thought must belong to the same user (MCP key). If the parent is not found or belongs to another user, the tool returns an error.

## Example

- New thought: `{ "content": "Remember to review the spec" }`
- Comment: `{ "content": "Done.", "parent_id": 42 }` or `{ "content": "Done.", "in_reply_to": 42 }`

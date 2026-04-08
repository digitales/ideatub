# MCP key labels — design

**Date:** 2026-04-08  
**Status:** Approved for implementation planning  
**Scope:** Settings → MCP keys: optional label on create, editable label afterward, secret remains read-only (masked).

## Goal

Make it easier to know **which** MCP key to revoke when a user has several. The **label** is the human-readable identifier; the **key material** stays non-editable and only fully visible once at creation.

## Context

- Table `user_mcp_keys` already has nullable `label`; `UserMcpKey` is fillable for `label`.
- `McpKeyController::store` already validates optional `label` (`nullable|string|max:64`) but the Blade settings page does not expose a label field on create.
- The list shows a masked secret plus a small label; there is no `update` route or policy method.

## Product rules

1. **Create (optional label)**  
   - User may submit an optional label (max 64 characters) with “Create key”.  
   - **Normalize:** trim surrounding whitespace.  
   - If missing or empty after trim → store **`Created in IdeaTub`** (keep current default behavior; decision **i**).

2. **Update (editable label)**  
   - User may change the label for an existing key.  
   - Same validation: `nullable|string|max:64`, trim.  
   - If empty after trim → store **`Created in IdeaTub`** (same default as create, so clearing the field resets to the familiar default).

3. **Key secret**  
   - Never editable.  
   - Existing keys: show **masked** value only (`ideatub_••••…` pattern as today).  
   - New key: one-time full display + copy (unchanged).

## Approach

Use **classic Laravel forms** (POST create, PATCH update, CSRF, redirect back): aligns with the rest of settings, minimal JS, straightforward feature tests. Defer fetch/Alpine-only saves unless UX feedback demands it.

## Backend

| Piece | Action |
|--------|--------|
| Route | `PATCH /settings/mcp-keys/{mcpKey}` → `McpKeyController@update`, name `settings.mcp-keys.update` |
| Controller | `update(Request $request, UserMcpKey $mcpKey)`: `authorize('update', $mcpKey)`; validate `label`; normalize empty → default string; `fill`/`update` **only** `label`; redirect with success flash |
| Policy | Add `update(User $user, UserMcpKey $key): bool` — owner only (`user_id` match), mirror `delete` |
| Model | No structural change; do not accept `key_hash` or other sensitive fields from update input |

**Authorization:** Route model binding + policy prevents cross-user updates (403).

## UI (`resources/views/settings/mcp-keys.blade.php`)

1. **Create block**  
   - Optional single-line **Label** input (placeholder e.g. “e.g. Cursor — work laptop”) above the submit button.  
   - Submit unchanged: still creates key server-side; flash still shows full key once.

2. **List rows**  
   - **Primary:** label — editable via a small form: text input + submit (e.g. “Save” / “Update label”). Pre-fill the input with the stored label; for legacy rows where `label` is `null`, show **`Created in IdeaTub`** in the input (or equivalent) so display matches the default semantics.  
   - **Read-only:** masked key line (not an `<input>`).  
   - **Metadata:** last used (if present), **Revoke** (unchanged).  
   - Visual hierarchy: label reads as the row title so “Revoke” maps to a named client/context.

3. **Validation errors**  
   - Standard session error display for create/update label field where applicable.

## Security & privacy

- Labels are user-supplied text: render with normal Blade escaping (`{{ }}`).  
- Do not log raw labels in debug paths unless already acceptable for user profile fields (treat as non-secret PII).  
- Update endpoint must not change `key_hash` or `user_id`.

## Testing

Extend `tests/Feature/McpKeySettingsTest.php` (or adjacent feature tests):

- Create **with** label → persisted trimmed value.  
- Create **without** / blank label → `Created in IdeaTub`.  
- Authenticated owner can **update** label; value persisted.  
- Update with blank label → `Created in IdeaTub`.  
- Cannot update another user’s key (403 or 404 per app convention for unauthorized model access).  
- Validation: over max length rejected.  
- Smoke: page still shows masked key, not full secret for existing rows.

## Out of scope

- Renaming keys from API/MCP tools.  
- Per-key rate limits (unless project-wide settings throttling already applies).  
- Migration of historical `null` labels: optional follow-up to backfill `Created in IdeaTub` for display consistency only — not required for this feature if UI treats null like empty (show default text when `label` is null in DB for old rows).

## Implementation handoff

After this spec is reviewed, use the **writing-plans** skill to produce a stepwise implementation plan (routes, policy, controller, view, tests).

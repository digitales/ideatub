# MCP Keys Implementation (Phase 1)

**Date**: 2025-03-10  
**Task**: 1.2 — User MCP keys table and model

## Overview

Per-user MCP keys for IdeaTub. Keys are stored **hashed only** (never plain). Use header `x-ideatub-key` when possible (see [2025-03-10-mcp-auth-multi-agent.md](../decisions/2025-03-10-mcp-auth-multi-agent.md)).

## Database

- **Table**: `user_mcp_keys`
- **Columns**: `id`, `user_id` (FK → users, cascade delete), `key_hash` (string, unique), `label` (nullable), `last_used_at` (nullable), `timestamps`

## Model

- **`App\Models\UserMcpKey`**
  - Fillable: `key_hash`, `label`, `last_used_at` (and `user_id` when creating)
  - `user()`: `belongsTo(User::class)`
  - `User::userMcpKeys()`: `hasMany(UserMcpKey::class)`
  - **Hashing**: SHA-256 (deterministic) so the same plain key hashes to the same value for storage and lookup. See `UserMcpKey::KEY_HASH_ALGO` and `UserMcpKey::hashKey(string $plainKey)`.
  - **Lookup**: `UserMcpKey::findByPlainKey(string $key): ?self` — hashes the given key and finds by `key_hash`.

## Creating keys (one per user)

Use the Artisan command to generate one MCP key per user. Plain keys are shown **once** in the console; users must copy and store them securely.

**Command:**

```bash
php artisan ideatub:create-mcp-keys
```

- Generates a key per user in the form `ideatub_` + 32 random characters.
- Stores only the SHA-256 hash in `user_mcp_keys`.
- Outputs each user’s plain key to the console (copy once).
- Skips users who already have a key unless `--force` is used.

**Options:**

- `--force` — Create a new key even if the user already has one (use with care; old key remains valid until revoked).

## Migration

Run migrations as usual so the `user_mcp_keys` table exists before running the command:

```bash
php artisan migrate
```

## References

- [Project spec](../decisions/project-spec.md)
- [MCP auth and multi-agent](../decisions/2025-03-10-mcp-auth-multi-agent.md)
- [IdeaTub implementation plan](../docs/superpowers/plans/2025-03-10-ideatub-implementation.md)

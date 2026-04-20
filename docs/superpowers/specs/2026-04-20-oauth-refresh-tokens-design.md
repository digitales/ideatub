# OAuth MCP Refresh Tokens Design

## Goal

Stop forcing users through full OAuth re-consent every hour on Claude, ChatGPT, and other MCP clients by adding refresh tokens to the IdeaTub OAuth MCP server, with OAuth 2.1-style rotation, reuse detection, an RFC 7009 revocation endpoint, and a user-facing Connected Apps settings page.

Target lifetimes:

- Access token: **1 hour** (unchanged shape: RS256 JWT).
- Refresh token: **30 days rolling** (opaque, rotated on every use).
- Absolute session cap: **90 days** from initial consent, then forced re-auth.

## Scope

### Included

- Refresh-token issuance on the authorization-code grant at `POST /oauth/token`.
- New `grant_type=refresh_token` handler at `POST /oauth/token` with rotation + reuse detection.
- RFC 7009 `POST /oauth/revoke` endpoint.
- Well-known metadata advertises `refresh_token` grant and `revocation_endpoint`.
- New migration: `oauth_mcp_refresh_token_families` + `oauth_mcp_refresh_tokens`.
- Reset `access_token_ttl_seconds` to 3600 once refresh lands (if it was raised as an interim fix).
- New config keys: `refresh_token_ttl_seconds`, `refresh_token_absolute_lifetime_seconds`.
- New `/settings/connected-apps` UI: list active OAuth sessions, revoke one, revoke all.
- Tests: issuance, rotation, reuse detection, absolute-lifetime cap, revocation endpoint, UI revocation, well-known metadata.

### Excluded

- MCP API-key auth (Cursor flow via `x-ideatub-key` / `?key=`). Unchanged.
- Consent screen, DCR, JWT signing keys, audience checks, and scope shape.
- Replacing JWT access tokens with opaque tokens.
- Access-token deny-list / immediate revocation of in-flight access tokens.
- Multi-tenant / org-level session management.
- Custom GPT Actions does not need a separate code path — it reuses `/oauth/token`, so it benefits automatically.

## Architecture and Ownership

### OauthMcpRefreshTokenService owns issuance, rotation, revocation

New `App\Services\OAuthMcpRefreshTokenService` is the canonical boundary for all refresh-token operations:

- `issueForCodeExchange(User $user, OauthMcpClient $client, string $resource, ?string $scope, Request $request): array{family: OauthMcpRefreshTokenFamily, raw: string}` — creates a family and its first token row.
- `rotate(string $rawToken, string $clientId, string $resource, ?string $requestedScope, Request $request): array{user: User, resource: string, scope: string, raw: string}` — verifies, applies reuse detection, and rotates in a transaction.
- `revokeFamily(OauthMcpRefreshTokenFamily $family, string $reason): void`.
- `revokeByRawToken(string $rawToken, string $reason): void` — used by `/oauth/revoke`.

The controller stays thin. All business logic lives here and is unit-tested without going through HTTP.

### OAuthServerController extended, not rewritten

`OAuthServerController::token()` branches on `grant_type`. A small private method handles each grant. Existing `authorization_code` validation is untouched; it gains a single call to `OAuthMcpRefreshTokenService::issueForCodeExchange()` before returning.

A new `revoke()` method is added to the same controller.

### ConnectedAppsController owns the settings UI

New `App\Http\Controllers\Settings\ConnectedAppsController` patterned after `McpKeyController`. Dedicated namespace keeps it separate from OAuth-protocol logic.

## Data Model

### `oauth_mcp_refresh_token_families`

One row per initial auth-code exchange. Represents a "connected app session" visible in the Connected Apps UI.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID (PK) | |
| `user_id` | FK → `users` | `cascadeOnDelete` |
| `client_id` | FK → `oauth_mcp_clients` | `cascadeOnDelete` |
| `resource` | `string(512)` | normalized via `OAuthMcpJwtService::normalizeResourceUrl()` |
| `scope` | `string`, nullable | |
| `user_agent` | `string(512)`, nullable | captured on each refresh for display |
| `ip_address` | `string(45)`, nullable | updated on each refresh |
| `last_used_at` | `timestamp`, nullable | set on each successful rotation |
| `issued_at` | `timestamp` | |
| `absolute_expires_at` | `timestamp` | `issued_at + refresh_token_absolute_lifetime_seconds` |
| `revoked_at` | `timestamp`, nullable | |
| `revoked_reason` | `string`, nullable | `user`, `reuse_detected`, `admin`, `absolute_expiry` |
| `created_at` / `updated_at` | `timestamp` | |

Index: `(user_id, revoked_at)` for the Connected Apps listing query.

### `oauth_mcp_refresh_tokens`

Many rows per family. The most recent row with `used_at IS NULL` and `expires_at > now()` is the current refresh token.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ULID (PK) | |
| `family_id` | FK → `oauth_mcp_refresh_token_families` | `cascadeOnDelete` |
| `token_hash` | `string(64)` | `hash('sha256', $rawToken)` — raw token never persisted |
| `expires_at` | `timestamp` | `min(now() + refresh_token_ttl_seconds, family.absolute_expires_at)` |
| `used_at` | `timestamp`, nullable | set when rotated |
| `replaced_by_id` | ULID FK → self, nullable | points to the new refresh-token row on rotation |
| `created_at` / `updated_at` | `timestamp` | |

Indexes: unique `token_hash`, plus `family_id`.

### Eloquent models

- `OauthMcpRefreshTokenFamily` — `hasMany` `refreshTokens`, `belongsTo` `user`, `client`. Scope `active()` = `whereNull('revoked_at')->where('absolute_expires_at', '>', now())`.
- `OauthMcpRefreshToken` — `belongsTo` `family`, `replacedBy`. Scope `usable()` = `whereNull('used_at')->where('expires_at', '>', now())`.

## Token Format

### Refresh token

- Opaque, not a JWT. Generated via `Str::random(64)` — matches how authorization codes are generated today in `OAuthServerController::createCodeAndRedirect()`.
- Returned to the client as a single string; never logged.
- Stored as `hash('sha256', $raw)` in `token_hash`. A DB compromise does not leak reusable tokens. Lookup on refresh hashes the incoming token and does a single indexed equality query.

### Access token

Unchanged. Still a self-contained RS256 JWT with `iss`/`sub`/`aud`/`iat`/`exp`/`scope`, signed with the existing `ideatub-mcp-1` key. TTL stays at 3600s. No new claims; family linkage is server-side only.

### Why opaque for refresh, JWT for access

Refresh tokens need server-side state for rotation and revocation regardless, so JWTs offer no benefit and make invalidation harder. Access tokens benefit from being stateless so every `/api/mcp` request avoids a DB lookup.

## Endpoints

### `POST /oauth/token` — `grant_type=authorization_code` (existing, extended)

All current validation unchanged. After `$code->update(['used_at' => now()])` and access-token issuance:

1. `OAuthMcpRefreshTokenService::issueForCodeExchange()` creates a family row and its first refresh-token row.
2. Response body adds `refresh_token`:

```json
{
  "access_token": "<JWT>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "<64-char opaque>",
  "scope": "ideatub:mcp"
}
```

### `POST /oauth/token` — `grant_type=refresh_token` (new)

Required form fields: `grant_type=refresh_token`, `refresh_token`, `client_id`, `resource`. Optional: `scope`.

Handler logic, in order (short-circuit on any failure with `400 invalid_grant`):

1. Hash the incoming `refresh_token` (sha256) and load the `oauth_mcp_refresh_tokens` row by `token_hash`. If none found → `invalid_grant`.
2. Load the family. If `family.revoked_at` is set or `now() > family.absolute_expires_at` → `invalid_grant`.
3. **Reuse detection:** if the token row has `used_at IS NOT NULL`, revoke the family (`revoked_reason='reuse_detected'`) and return `invalid_grant`. Log a warning (`oauth.mcp.refresh.reuse_detected`).
4. Verify `client_id` matches `family.client_id` and `resource` matches `family.resource` under normalized compare.
5. Validate scope: requested scope must be identical or a subset of `family.scope`.
6. Rotate inside a single DB transaction:
   - Insert a new `OauthMcpRefreshToken` for the same `family_id`. `expires_at = min(now() + refresh_token_ttl_seconds, family.absolute_expires_at)`.
   - Update the old row: `used_at = now()`, `replaced_by_id = <new row id>`.
   - Update family: `last_used_at = now()`, refresh `user_agent` / `ip_address`.
7. Issue a new access-token JWT for `family.user` + `family.resource`.
8. Return the same response shape as the code grant, with the new refresh token.

### `POST /oauth/revoke` — RFC 7009 (new)

Required form fields: `token`, `client_id`. Optional: `token_type_hint` (`refresh_token` | `access_token`).

Behavior:

- If `token_type_hint=refresh_token` or unspecified: hash the token, look up in `oauth_mcp_refresh_tokens`. If found and the caller's `client_id` matches the family's `client_id`, revoke the family (`revoked_reason='user'`).
- If `token_type_hint=access_token`: v1 does not support access-token revocation (see Known Limitations). Treated as a no-op.
- Always return `200` regardless of whether the token existed, per RFC 7009 §2.2.
- CSRF-exempt in `bootstrap/app.php` alongside `oauth/register` and `oauth/token`.

### Well-known metadata

`OAuthWellKnownController::authorizationServer()` response additions:

```json
{
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "revocation_endpoint": "<issuer>/oauth/revoke",
  "revocation_endpoint_auth_methods_supported": ["none"]
}
```

### Middleware

`AuthenticateOAuthBearer` unchanged. It continues to verify JWT access tokens statelessly.

## Flows

### Initial issuance

Authorization-code flow as today, plus one side-effect: the token response now includes `refresh_token`, and a family + first token row exist in DB.

### Silent refresh

When the access token expires (after 1 hour), the MCP client posts `grant_type=refresh_token` with the current refresh token. The server validates, rotates in a transaction, and returns a new access token and a new refresh token. The user sees nothing. This repeats every hour for up to 30 days since the last rotation, up to the 90-day absolute cap.

### Reuse detection

If an old (rotated) refresh token is replayed — for example, because an attacker exfiltrated it and the legitimate client has already rotated to a new one — the handler sees `used_at IS NOT NULL` and revokes the entire family. Both the attacker and the legitimate client are forced to re-consent. This is the OAuth 2.1 recommended "burn the session" posture.

### Absolute lifetime cap

Every rotation computes `new.expires_at = min(now() + 30d, family.absolute_expires_at)`. Once 90 days have elapsed since initial consent, refresh calls fail and the user is bounced through `/oauth/authorize` again.

### Manual revocation paths

- Client-initiated — MCP client calls `/oauth/revoke` on disconnect → family revoked (`reason=user`).
- User-initiated — user clicks Disconnect in `/settings/connected-apps` → family revoked (`reason=user`).
- Automatic — reuse detected → family revoked (`reason=reuse_detected`).

After revocation, any already-issued access-token JWT remains valid until its `exp` — up to 1 hour. See Known Limitations.

## Connected Apps UI

### Routes

```
GET    /settings/connected-apps              name: settings.connected-apps.index
DELETE /settings/connected-apps/{family}     name: settings.connected-apps.destroy
DELETE /settings/connected-apps              name: settings.connected-apps.destroy-all
```

All behind `auth` middleware, grouped alongside the existing `mcp-keys` routes.

### Controller

`App\Http\Controllers\Settings\ConnectedAppsController`:

- `index` — loads `OauthMcpRefreshTokenFamily::active()->where('user_id', auth()->id())->with('client')->orderByDesc('last_used_at')->get()`.
- `destroy(OauthMcpRefreshTokenFamily $family)` — authorize `$family->user_id === auth()->id()`, call `OAuthMcpRefreshTokenService::revokeFamily($family, 'user')`.
- `destroyAll` — bulk-revoke all active families for the current user.

### View

New `resources/views/settings/connected-apps.blade.php`, patterned after `settings/mcp-keys.blade.php`. Per family:

- Client label — prefer the first redirect-URI host (e.g. `claude.ai`, `chatgpt.com`); fall back to truncated `client_id`.
- Scope.
- Last used (humanized), last IP, user-agent summary.
- Connected timestamp (`issued_at`) and expires timestamp (`absolute_expires_at`).
- Disconnect button → `settings.connected-apps.destroy` with confirm.

Above the list: Disconnect all button → `settings.connected-apps.destroy-all`.

Empty state: "No OAuth-connected apps. Claude, ChatGPT, and other MCP clients you connect will appear here."

### Navigation

Add a Connected Apps link in the profile menu alongside the existing MCP key entry.

## Config and Environment

`config/oauth-mcp.php` additions:

```php
'access_token_ttl_seconds' => (int) env('OAUTH_MCP_ACCESS_TOKEN_TTL', 3600),

'refresh_token_ttl_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_TTL', 60 * 60 * 24 * 30),

'refresh_token_absolute_lifetime_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_ABSOLUTE_LIFETIME', 60 * 60 * 24 * 90),
```

`.env.example` additions:

```
OAUTH_MCP_ACCESS_TOKEN_TTL=3600
OAUTH_MCP_REFRESH_TOKEN_TTL=2592000
OAUTH_MCP_REFRESH_TOKEN_ABSOLUTE_LIFETIME=7776000
```

`bootstrap/app.php`: add `oauth/revoke` to the CSRF exception list.

## Error Handling and Safety

- All refresh-token row writes that invalidate the old token and insert the new one happen in a single DB transaction to avoid half-rotated state.
- The raw refresh-token string is never written to logs. Log fields refer only to `family_id`, token row id, and user id.
- `/oauth/revoke` always returns `200` (RFC 7009 §2.2) — no token-probing oracle.
- Reuse detection revokes the entire family regardless of which token in the chain was replayed.
- The family's `absolute_expires_at` is authoritative; individual token `expires_at` is bounded by it.
- Cross-user revocation attempts return `403`.

## Testing

### Feature — `tests/Feature/OAuthMcpRefreshTokenTest.php`

- Authorization-code grant returns a `refresh_token` and creates a family + first token row.
- `grant_type=refresh_token` with a valid token returns a new access token and a new refresh token, rotates the old row, sets `replaced_by_id`.
- Replay of a rotated refresh token returns `400 invalid_grant` and revokes the family with reason `reuse_detected`.
- `client_id` mismatch returns `400 invalid_grant` and does not mutate state.
- `resource` mismatch (normalized compare) returns `400 invalid_grant`.
- Expired refresh token returns `400 invalid_grant`.
- Refresh past `absolute_expires_at` returns `400 invalid_grant` and the new row (if any) respects the cap.
- Scope upgrade attempt rejected; scope downgrade allowed.

### Feature — `tests/Feature/OAuthMcpRevokeEndpointTest.php`

- Valid refresh token → `200`, family revoked with reason `user`; subsequent refresh call fails.
- Unknown token → `200` (opacity per RFC 7009).
- Access-token hint → `200`, no-op.
- CSRF-exempt path works without a session.

### Feature — `tests/Feature/ConnectedAppsSettingsTest.php`

- Unauthenticated users are redirected to login.
- Listing shows only the current user's active families.
- Revoked and expired families are hidden.
- `DELETE /settings/connected-apps/{family}` sets `revoked_at`; cross-user revocation returns `403`.
- `DELETE /settings/connected-apps` revokes all active families for the current user only.

### Feature — well-known metadata

- `grant_types_supported` includes `refresh_token`.
- `revocation_endpoint` present and equal to `<issuer>/oauth/revoke`.

### Unit — `tests/Unit/OAuthMcpRefreshTokenServiceTest.php`

- `issueForCodeExchange` persists a family + token row and returns a raw token matching the stored hash.
- `rotate` successful path, reuse path, expired path, absolute-cap path, client/resource mismatch.
- `revokeFamily` sets `revoked_at` and `revoked_reason`.
- `revokeByRawToken` is a no-op on unknown tokens and revokes the family on known ones.

## Rollout

1. Ship the migration (`oauth_mcp_refresh_token_families`, `oauth_mcp_refresh_tokens`). Purely additive.
2. Ship the service, controller changes, view, routes, and well-known update.
3. Reset `OAUTH_MCP_ACCESS_TOKEN_TTL=3600` in production if it was raised as an interim fix.
4. Ask users to re-authenticate Claude and ChatGPT connectors once. Already-issued access tokens keep working until their 1-hour expiry; no back-compat migration for them.
5. Monitor logs:
   - `oauth.mcp.refresh.reuse_detected` warnings should be zero under normal operation.
   - `oauth.mcp.refresh.success` counter should grow hourly per active connector.
6. After stability, consider tightening `access_token_ttl_seconds` further (e.g. 15 minutes). Out of scope for this spec.

## Known Limitations

- Access tokens are self-contained JWTs, so revoking a family does not invalidate an already-issued access token until its `exp`. Worst-case window: 1 hour. Accepted tradeoff for stateless verification.
- `/oauth/revoke` does not attempt to invalidate access tokens directly (no deny-list in v1).

## Success Criteria

- Claude stays connected without user re-auth for at least 30 days of normal daily use.
- Users are prompted to re-consent no later than 90 days after initial connect.
- Replay of a rotated refresh token triggers family revocation; a stolen refresh token cannot persist past the legitimate client's next refresh call.
- Users can disconnect any OAuth client from `/settings/connected-apps` in one click.
- All new and extended tests pass.

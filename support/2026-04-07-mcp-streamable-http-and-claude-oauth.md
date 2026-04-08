# MCP Streamable HTTP, Claude remote connector, and OAuth (Apr 2026)

Summary of work and troubleshooting from a single thread: implementing MCP Streamable HTTP on IdeaTub, then fixing Claude Desktop / production OAuth and auth visibility issues.

## Goals

- Support **MCP Streamable HTTP** (spec **2025-03-26**) on the same URL as existing JSON-RPC so **Claude remote MCP** can complete the transport handshake after OAuth.
- Keep **header-based JSON-RPC** clients working (e.g. Cursor with `x-ideatub-key`, no `text/event-stream` in `Accept`).
- Avoid **API keys in query strings** for Claude; OAuth + Bearer preferred.
- Preserve **`oauth-mcp.resource`** alignment with **`https://…/api/mcp`** where possible.

## What was implemented (codebase)

### Streamable HTTP (`POST /api/mcp`)

- **Detection:** Treat as Streamable when `Accept` includes **both** `application/json` and `text/event-stream` (per spec). Otherwise **legacy JSON-RPC** unchanged.
- **Session:** `Mcp-Session-Id` on `initialize` response; required on later RPCs with an `id`; stored in **cache** (TTL `MCP_SESSION_TTL_SECONDS`, default 86400). Service: `App\Services\McpSessionService`.
- **`notifications/initialized`:** **202** empty body in Streamable mode; legacy still returns small JSON.
- **GET `/api/mcp`:** If `Accept` includes `text/event-stream` → **405** + `Allow: DELETE, GET, POST`; else unchanged discovery JSON for existing clients.
- **DELETE `/api/mcp`:** End session when `Mcp-Session-Id` + valid auth.
- **Origin:** When `Origin` is present on Streamable requests, host must match allowlist (`APP_URL` host, Claude/ChatGPT/Cursor defaults, plus `MCP_STREAMABLE_ALLOWED_HOSTS`).
- **Config:** `config/mcp.php`. **Tests:** `tests/Feature/McpStreamableHttpTest.php`.

### OAuth / JWT hardening

- **CSRF:** `POST /oauth/register` and `POST /oauth/token` excluded from CSRF in `bootstrap/app.php` (machine clients have no session cookie). **419** on DCR was the symptom before this fix.
- **Resource / issuer matching:** `OAuthMcpJwtService::normalizeResourceUrl()` so JWT `aud` / `iss` tolerate trailing slashes and scheme/host case vs `.env`.
- **Authorization codes:** `resource` stored normalized; token exchange compares normalized values.
- **Well-known JSON:** `resource` and `issuer` emitted in normalized form from `OAuthWellKnownController`.
- **Keys on ephemeral hosts:** `OAUTH_MCP_PRIVATE_KEY_B64` / `OAUTH_MCP_PUBLIC_KEY_B64` (or inline PEM) via `App\Support\OAuthMcpPemLoader`. **RuntimeException** if neither env nor `storage/app/oauth-mcp-*.pem` is available.
- **JWT clock skew:** Leeway (120s) around `JWT::decode` in `OAuthMcpJwtService`.
- **401 `WWW-Authenticate`:** `resource_metadata` base URL uses **`oauth-mcp.issuer`**, not only `app.url`.

### Bearer token visibility (nginx / PHP-FPM / proxies)

- **`public/index.php`:** Backfill `$_SERVER['HTTP_AUTHORIZATION']` from `REDIRECT_HTTP_AUTHORIZATION`, `REDIRECT_REDIRECT_HTTP_AUTHORIZATION`, `HTTP_X_AUTHORIZATION`, `getallheaders()`, `apache_request_headers()`.
- **`App\Support\BearerTokenExtractor`:** Collects Bearer from `Authorization`, server vars, `X-Authorization`, `X-Forwarded-Authorization`, and **raw JWT** from `X-Access-Token` / `X-MCP-Access-Token` / `MCP-Access-Token` (for CDNs that strip `Authorization`).
- **`McpController`:** Requires `OAuthMcpJwtService` (no optional null). Uses `BearerTokenExtractor` + optional logging.
- **`docker/nginx.conf`:** Example `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.

### Debugging (Laravel Cloud / logs)

- **`MCP_LOG_OAUTH_FAILURES=true`:** Logs JWT verification failures (exception class + message; **never** the token).
- **`MCP_DEBUG_AUTH=true`:** Logs one **`MCP auth debug`** line per MCP auth attempt: streamable flag, JSON-RPC method, whether `Authorization` / `HTTP_AUTHORIZATION` / Bearer extraction / key headers / `X-Access-Token` are present, **bearer length only**, and **`server_keys_matching_auth_or_token`** (key names only).

## Production symptoms observed

| Observation | Interpretation |
|-------------|----------------|
| `POST /oauth/token` **200** then `POST /api/mcp` **401** (Claude-User, very fast) | Token issued but **Bearer not applied or not visible to PHP**, or JWT verify failed. |
| `POST /oauth/register` **419** | Laravel **CSRF** blocking DCR → fixed by CSRF exceptions. |
| `OAuth MCP private key not found` | No PEM on disk on ephemeral deploy → use **`OAUTH_MCP_*_B64`** or persistent storage for `storage/app/oauth-mcp-*.pem`. |
| `GET /api/mcp` **405** after 401 | MCP client **fallback** after POST failure (Streamable GET probe); not the root cause. |
| `python-httpx` + `Claude-User` user agents | Consistent with **Anthropic** backend calling IdeaTub. |

## Operational checklist (Claude + Laravel Cloud)

1. **Env:** `APP_URL`, `OAUTH_MCP_ISSUER`, `OAUTH_MCP_RESOURCE` (`…/api/mcp`) aligned; **www vs apex** consistent.
2. **Keys:** `OAUTH_MCP_PRIVATE_KEY_B64` + `OAUTH_MCP_PUBLIC_KEY_B64` (or durable PEM files); **same pair** for sign + verify.
3. **Deploy:** Includes `public/index.php` Bearer backfill and latest `McpController` / `BearerTokenExtractor`.
4. **If 401 persists:** Enable **`MCP_DEBUG_AUTH`**; if `bearer_extracted` is false, ask host (or Cloudflare) to **pass `Authorization` to PHP-FPM** or **mirror to `X-Access-Token`** via Transform Rule.
5. **Security:** Turn off debug env vars after investigation.

## Key files touched (reference)

- `app/Http/Controllers/Api/McpController.php` — Streamable + legacy, sessions, auth, debug context.
- `app/Services/McpSessionService.php`, `config/mcp.php`, `routes/api.php` (DELETE).
- `app/Services/OAuthMcpJwtService.php`, `app/Support/OAuthMcpPemLoader.php`, `config/oauth-mcp.php`.
- `app/Support/BearerTokenExtractor.php`.
- `app/Http/Controllers/OAuthServerController.php`, `OAuthWellKnownController.php`.
- `app/Http/Middleware/AuthenticateOAuthBearer.php`.
- `bootstrap/app.php` — CSRF exceptions for `oauth/register`, `oauth/token`.
- `public/index.php` — `Authorization` backfill.
- `docker/nginx.conf` — FastCGI Authorization example.
- `.env.example` — MCP/OAuth env hints.

## Non-goals (this thread)

- Re-enabling **`?key=`** query auth for Claude.
- Full SSE batching / resumable streams (v1 kept minimal: JSON responses allowed by spec, optional 405 GET).

## Related docs

- `docs/mcp-integration-guide.md`, `docs/cursor-mcp-integration.md` (may need periodic updates to mention Streamable `Accept` and OAuth).
- MCP spec: [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports), [Authorization](https://modelcontextprotocol.io/specification/2025-03-26/basic/authorization).

# OAuth for ChatGPT MCP Connector — Implementation Plan

**Goal:** Let users connect IdeaTub to ChatGPT via OAuth ("Sign in with IdeaTub") instead of pasting an MCP key.

**Spec:** [MCP Authorization](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization), [OpenAI Apps SDK Auth](https://developers.openai.com/apps-sdk/build/auth/).

---

## 1. Components

| Component | Responsibility |
|-----------|----------------|
| **Protected resource metadata** | `GET /.well-known/oauth-protected-resource` — describes MCP server and points to auth server. |
| **Authorization server metadata** | `GET /.well-known/oauth-authorization-server` — discovery (authorize, token, register, PKCE, scopes). |
| **JWKS** | `GET /.well-known/jwks.json` — public key(s) for JWT verification. |
| **Dynamic client registration (DCR)** | `POST /oauth/register` — ChatGPT registers, gets `client_id`. |
| **Authorize** | `GET /oauth/authorize` — user logs in (if needed), sees consent, redirect back with `code`. |
| **Token** | `POST /oauth/token` — exchange `code` + `code_verifier` for JWT access token. |
| **MCP** | Accept `Authorization: Bearer <token>`; verify JWT (iss, aud, exp, sub), resolve user; else 401 with `WWW-Authenticate`. |

---

## 2. Redirect URIs to allowlist

- Production: `https://chatgpt.com/connector/oauth/{callback_id}` (ChatGPT shows the exact URI in app management).
- Legacy: `https://chatgpt.com/connector_platform_oauth_redirect`
- Review: `https://platform.openai.com/apps-manage/oauth`

---

## 3. Flow (echo `resource`)

- ChatGPT sends `resource=https://ideatub.com/api/mcp` (or our app URL) on authorize and token requests.
- We store `resource` with the authorization code and put it in the JWT as `aud` (audience) so the MCP server can verify the token was minted for it.

---

## 4. Implementation chunks

1. **Config + well-known + JWKS** — `config/oauth-mcp.php`, routes and controllers for `.well-known/*` and JWKS (RSA key pair in env/storage).
2. **Migrations** — `oauth_clients` (client_id, redirect_uris JSON), `oauth_authorization_codes` (code, client_id, user_id, redirect_uri, code_challenge, code_challenge_method, resource, scopes, expires_at).
3. **DCR** — `POST /oauth/register`, validate redirect_uris, create client, return `client_id` (public client).
4. **Authorize** — `GET /oauth/authorize`, validate params, require auth, show consent view, create code (store resource + code_challenge), redirect to client with `code` and `state`.
5. **Token** — `POST /oauth/token`, validate code + code_verifier (S256), issue JWT (sub=user_id, aud=resource, iss, exp, scope).
6. **MCP Bearer** — In `McpController`: if `Authorization: Bearer` present, verify JWT, resolve user from `sub`; else if `?key=` or `x-ideatub-key`, existing key flow; else 401 with `WWW-Authenticate: Bearer resource_metadata="...", scope="ideatub:mcp"`.

---

## 5. Scopes

- `ideatub:mcp` — access to MCP tools (search, browse, stats, capture). Single scope is enough for now.

---

## 6. Security

- PKCE required (S256).
- Authorization codes: single-use, short-lived (e.g. 10 min).
- JWT: short-lived (e.g. 1 hour), signed with RS256, `aud` = resource URL.
- Allowlist redirect_uris per client; reject redirect_uri not in client's list.

---

## 7. Deployment checklist

1. Set `APP_URL` and (if different) `OAUTH_MCP_ISSUER` and `OAUTH_MCP_RESOURCE` in production.
2. Run `php artisan ideatub:oauth-mcp-keys` once (generates `storage/app/oauth-mcp-private.pem` and `oauth-mcp-public.pem`). Keep private key secret; add `storage/app/oauth-mcp-*.pem` to `.gitignore`.
3. In ChatGPT app management, add the connector with **OAuth** (not API key). ChatGPT will discover `.well-known/oauth-protected-resource` and run the authorization code + PKCE flow.
4. Allowlist redirect URIs: `https://chatgpt.com/connector/oauth/*`, `https://chatgpt.com/connector_platform_oauth_redirect`, `https://platform.openai.com/apps-manage/oauth` (for review). These are enforced via `config/oauth-mcp.php` → `allowed_redirect_hosts` (chatgpt.com, platform.openai.com).

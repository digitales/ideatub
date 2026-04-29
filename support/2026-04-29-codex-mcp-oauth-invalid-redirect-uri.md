# Codex MCP OAuth `invalid_redirect_uri` — Support investigation

**Date**: 2026-04-29  
**Status**: Resolved (code)  
**Reported By**: Internal  

## Issue description

`codex mcp add ideatub --url https://ideatub.com/api/mcp` fails during OAuth dynamic registration:

`HTTP 400 ... {"error":"invalid_redirect_uri","error_description":"Redirect URIs must be allowlisted"}`

## Root cause

Codex registers via RFC 7591 (`POST /oauth/register`) with loopback `redirect_uris` (`http://localhost:...`, `http://127.0.0.1:...`, or `http://[::1]:...`) for the local callback server. IdeaTub’s DCR allowlist only included ChatGPT and Claude hosts, so `normalizeRedirectUris()` dropped every URI and registration returned `invalid_redirect_uri`.

Relevant code: `App\Http\Controllers\OAuthServerController::register()` and `normalizeRedirectUris()`; config `config/oauth-mcp.php` → `allowed_redirect_hosts`.

## Resolution

Add loopback hosts to `allowed_redirect_hosts`: `localhost`, `127.0.0.1`, `[::1]` (PHP `parse_url` reports IPv6 loopback with brackets).

Deploy to production for `https://ideatub.com` to unblock Codex users.

## Edge cases

- Custom `mcp_oauth_callback_url` in `~/.codex/config.toml` pointing at a non-loopback host (e.g. devbox ingress) still requires that host to appear in `allowed_redirect_hosts` or an equivalent server-side allowlist extension.

## References

- `config/oauth-mcp.php`
- `tests/Feature/OAuthMcpLoopbackRedirectAllowlistTest.php`
- Codex docs: OAuth callback configuration (`mcp_oauth_callback_port`, `mcp_oauth_callback_url`)

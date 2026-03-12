# Custom GPT Actions + OAuth (IdeaTub REST API)

Use a **Custom GPT** to search and capture IdeaTub thoughts via REST with **OAuth** so users sign in to IdeaTub and the GPT acts on their behalf—no dev mode or API keys in the chat.

## What’s in place

- **REST API** (OAuth Bearer only):
  - `GET /api/thoughts/search?query=...&limit=10` — semantic search
  - `GET /api/thoughts/recent?limit=10` — recent thoughts
  - `GET /api/thoughts/stats` — thought count
  - `POST /api/thoughts` — capture a thought (body: `{ "content": "..." }`)
- **OAuth** is the same as the MCP connector: authorize → consent → token. Tokens issued for the **REST API resource** are valid for these endpoints.

## Configure the Custom GPT

1. **Create a Custom GPT** and open **Configure → Actions**.

2. **Import schema**
   - Copy [openapi-thoughts-api.yaml](./openapi-thoughts-api.yaml).
   - Replace `https://your-ideatub-domain.com` with your real IdeaTub base URL (e.g. `https://ideatub.com`).
   - In the schema, set `servers[0].url` to your base URL if you use absolute URLs, or leave as `/` and set **Server URL** in the GPT to your base URL.
   - Paste the edited schema into the GPT’s “Import from URL” or “Paste JSON” (convert YAML to JSON if the editor only accepts JSON).

3. **Authentication**
   - Set **Authentication** to **OAuth**.
   - **Authorization URL**: `{BASE_URL}/oauth/authorize`  
     e.g. `https://ideatub.com/oauth/authorize`
   - **Token URL**: `{BASE_URL}/oauth/token`  
     e.g. `https://ideatub.com/oauth/token`
   - **Scope**: `ideatub:mcp` (or leave default if your server only supports this scope).
   - **Client ID**: From IdeaTub’s **dynamic client registration**.  
     - Send `POST {BASE_URL}/oauth/register` with body:  
       `{ "redirect_uris": [ "https://chatgpt.com/aip/g-YOUR-GPT-ID/oauth/callback" ] }`  
       (or `https://chat.openai.com/...` depending on the GPT’s callback; ensure the host is in IdeaTub’s `allowed_redirect_hosts`).
     - Use the returned `client_id` in the Custom GPT.
   - **Client secret**: IdeaTub’s OAuth uses PKCE only; if the GPT UI requires a secret, leave it blank or use a placeholder if the implementation allows.
   - **Resource (OAuth 2.1)**  
     For tokens to be accepted by the REST API, the token request must include the **resource** parameter. Set it to your REST API resource URL:  
     `{BASE_URL}/api/thoughts`  
     e.g. `https://ideatub.com/api/thoughts`  
     If the Custom GPT configuration has a “Custom parameters” or “Resource” field for the token request, set it there. If not, check whether the platform sends a default resource; you may need to use the same value as in IdeaTub’s `OAUTH_MCP_RESOURCE_API` env (which defaults to `{APP_URL}/api/thoughts`).

4. **Redirect URI**
   - After creating the GPT, OpenAI will show a callback URL like  
     `https://chatgpt.com/aip/g-xxxxx/oauth/callback`.  
   - Register it with IdeaTub by calling `POST /oauth/register` with that URL in `redirect_uris` and use the returned `client_id` (or add it to an existing client if your app supports multiple redirect URIs).  
   - Hosts `chatgpt.com`, `chat.openai.com`, and `platform.openai.com` are already in IdeaTub’s `allowed_redirect_hosts`.

5. **Instructions**
   - In the GPT’s instructions, tell it when to call each action (e.g. “Use search_thoughts when the user asks to find past notes; use capture_thought when they ask to save something.”).

## Environment

- **Resource for REST API**  
  IdeaTub accepts tokens whose audience is either the MCP resource or the REST API resource. The REST resource is set by:
  - `OAUTH_MCP_RESOURCE_API` (default: `{APP_URL}/api/thoughts`).
- Ensure **OAuth MCP** is enabled and keys are generated:  
  `php artisan ideatub:oauth-mcp-keys`

## Testing

1. Open the Custom GPT and trigger an action (e.g. “Search my IdeaTub for notes about X”).
2. You should see “Sign in to IdeaTub” (or similar); complete login and consent on IdeaTub.
3. After redirect, the GPT should call the REST API with a Bearer token and return your thoughts or confirm capture.

If you get 401, check that the token was issued with `resource` = your REST API resource URL (`{BASE_URL}/api/thoughts`) and that `OAUTH_MCP_RESOURCE_API` matches.

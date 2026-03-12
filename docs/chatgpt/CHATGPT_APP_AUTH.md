# ChatGPT MCP OAuth Integration Guide (IdeaTub)

## Goal

Allow ChatGPT to securely access the IdeaTub MCP server using OAuth so
users can connect their accounts without exposing API keys.

Endpoint: https://ideatub.com/api/mcp

## Architecture

ChatGPT → OAuth Login → IdeaTub → MCP Tools

Steps: 1. User installs the IdeaTub ChatGPT app. 2. ChatGPT detects
OAuth requirements from MCP metadata. 3. ChatGPT shows a "Connect
account" button. 4. User authenticates via IdeaTub OAuth. 5. ChatGPT
stores the issued token. 6. Tool calls include: Authorization: Bearer
`<token>`{=html}

## Required Endpoints

### MCP Endpoint

    POST /api/mcp

Handles: - tool discovery - tool execution - streaming responses

### OAuth Protected Resource Metadata

Expose at:

    /.well-known/oauth-protected-resource

Example structure:

``` json
{
  "resource": "https://ideatub.com/api/mcp",
  "authorization_servers": [
    "https://ideatub.com"
  ]
}
```

### OAuth Authorization Endpoint

    GET /oauth/authorize

### OAuth Token Endpoint

    POST /oauth/token

### Token Validation

Each MCP request must validate:

-   signature
-   expiry
-   issuer
-   audience

Reject invalid tokens with:

HTTP 401

## Tool Security Configuration

Example tool definition:

``` json
{
  "name": "search_thoughts",
  "description": "Search the user's stored thoughts.",
  "securitySchemes": [
    {
      "type": "oauth2"
    }
  ]
}
```

Recommended tools:

-   search_thoughts
-   get_thought
-   list_recent_thoughts
-   capture_thought
-   update_thought
-   delete_thought

All should require OAuth.

## Token Verification Middleware

Typical steps:

1.  Read Authorization header
2.  Extract Bearer token
3.  Validate JWT or token introspection
4.  Map token subject → IdeaTub user
5.  Attach user context to MCP request

## Security Recommendations

-   Never trust model-generated input
-   Validate all tool arguments
-   Prevent cross-user data access
-   Log every write operation
-   Rate limit tool execution

## Observability

Log:

-   OAuth start
-   OAuth callback
-   Token validation failures
-   Tool invocation
-   Latency and errors

## Production Hardening

-   HTTPS only
-   No query param secrets
-   Stable streaming responses
-   Graceful timeout handling

## Testing Prompts

Example test prompts:

Search: "Use IdeaTub to find notes about Evernote sync."

Write: "Save this thought in IdeaTub: Test OAuth edge cases."

Recent: "Show my most recent IdeaTub notes."

## Deployment Flow

1.  Enable ChatGPT Developer Mode
2.  Connect MCP endpoint
3.  Test OAuth linking
4.  Validate tool calls
5.  Capture screenshots
6.  Submit app for review

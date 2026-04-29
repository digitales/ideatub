<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth MCP (ChatGPT connector)
    |--------------------------------------------------------------------------
    |
    | URLs must be absolute and HTTPS in production. Run:
    |   php artisan ideatub:oauth-mcp-keys
    | to generate the RSA key pair for JWT (storage/app/oauth-mcp-*.pem).
    |
    */

    'enabled' => env('OAUTH_MCP_ENABLED', true),

    'issuer' => env('OAUTH_MCP_ISSUER', env('APP_URL', 'http://localhost:8000')),

    'resource' => env('OAUTH_MCP_RESOURCE', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/mcp'),

    /*
    | REST API resource URL for Custom GPT Actions (OAuth audience).
    | Use this as the "resource" when configuring Custom GPT OAuth so tokens work for /api/thoughts/*.
    */
    'resource_api' => env('OAUTH_MCP_RESOURCE_API', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/thoughts'),

    'scope' => 'ideatub:mcp',

    'authorization_code_ttl_seconds' => 600, // 10 minutes

    'access_token_ttl_seconds' => (int) env('OAUTH_MCP_ACCESS_TOKEN_TTL', 3600),

    'refresh_token_ttl_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_TTL', 60 * 60 * 24 * 30),

    'refresh_token_absolute_lifetime_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_ABSOLUTE_LIFETIME', 60 * 60 * 24 * 90),

    /*
    | Keys: use PEM files locally, or B64/inline PEM env vars on ephemeral hosts (Laravel Cloud, etc.).
    */
    'private_key_path' => env('OAUTH_MCP_PRIVATE_KEY_PATH', storage_path('app/oauth-mcp-private.pem')),

    'public_key_path' => env('OAUTH_MCP_PUBLIC_KEY_PATH', storage_path('app/oauth-mcp-public.pem')),

    'private_key_b64' => env('OAUTH_MCP_PRIVATE_KEY_B64'),

    'public_key_b64' => env('OAUTH_MCP_PUBLIC_KEY_B64'),

    'private_key_pem' => env('OAUTH_MCP_PRIVATE_KEY'),

    'public_key_pem' => env('OAUTH_MCP_PUBLIC_KEY'),

    'allowed_redirect_hosts' => [
        'chatgpt.com',
        'chat.openai.com',
        'platform.openai.com',
        // Claude custom remote MCP connectors (DCR + OAuth callback)
        // https://support.claude.com/en/articles/11503834-building-custom-connectors-via-remote-mcp-servers
        'claude.ai',
        'claude.com',
        // Local MCP clients (e.g. Codex CLI `codex mcp add` / `codex mcp login`) use RFC 8252-style
        // loopback redirect URIs with dynamic ports during dynamic client registration.
        'localhost',
        '127.0.0.1',
        '[::1]',
    ],

];

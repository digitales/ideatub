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

    'access_token_ttl_seconds' => 3600, // 1 hour

    'private_key_path' => storage_path('app/oauth-mcp-private.pem'),

    'public_key_path' => storage_path('app/oauth-mcp-public.pem'),

    'allowed_redirect_hosts' => [
        'chatgpt.com',
        'chat.openai.com',
        'platform.openai.com',
        // Claude custom remote MCP connectors (DCR + OAuth callback)
        // https://support.claude.com/en/articles/11503834-building-custom-connectors-via-remote-mcp-servers
        'claude.ai',
        'claude.com',
    ],

];

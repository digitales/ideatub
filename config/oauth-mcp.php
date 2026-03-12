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

    'scope' => 'ideatub:mcp',

    'authorization_code_ttl_seconds' => 600, // 10 minutes

    'access_token_ttl_seconds' => 3600, // 1 hour

    'private_key_path' => storage_path('app/oauth-mcp-private.pem'),

    'public_key_path' => storage_path('app/oauth-mcp-public.pem'),

    'allowed_redirect_hosts' => [
        'chatgpt.com',
        'platform.openai.com',
    ],

];

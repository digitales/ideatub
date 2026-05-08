<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Streamable HTTP (MCP 2025-03-26)
    |--------------------------------------------------------------------------
    |
    | POST is treated as Streamable HTTP when Accept lists both application/json
    | and text/event-stream (spec requirement). Other clients keep legacy JSON-RPC.
    |
    */

    'session_ttl_seconds' => (int) env('MCP_SESSION_TTL_SECONDS', 86400),

    /*
    | When true, JWT verification failures on /api/mcp are logged (message only, never the token).
    */
    'log_oauth_failures' => filter_var(env('MCP_LOG_OAUTH_FAILURES', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | When true, each /api/mcp auth resolution logs a single info line (no secrets):
    | whether Authorization / Bearer / API key headers were seen, token length only, streamable flag, JSON-RPC method.
    */
    'debug_auth' => filter_var(env('MCP_DEBUG_AUTH', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Log each tools/call invocation. Disabled by default to avoid noisy warning logs.
    */
    'log_tool_calls' => filter_var(env('MCP_LOG_TOOL_CALLS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Optional comma-separated extra hostnames allowed in Origin (in addition to
    | APP_URL host and the built-in defaults in McpController).
    */
    'streamable_allowed_hosts_extra' => env('MCP_STREAMABLE_ALLOWED_HOSTS', ''),

];

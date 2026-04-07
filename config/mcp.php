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
    | Optional comma-separated extra hostnames allowed in Origin (in addition to
    | APP_URL host and the built-in defaults in McpController).
    */
    'streamable_allowed_hosts_extra' => env('MCP_STREAMABLE_ALLOWED_HOSTS', ''),

];

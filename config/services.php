<?php

return [
    'mcp' => [
        'access_key' => env('MCP_ACCESS_KEY'),
    ],
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'metadata_model' => env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'evernote' => [
        'access_token' => env('EVERNOTE_ACCESS_TOKEN'),
        /*
         * Notebook mapping: type or tag → Evernote notebook GUID.
         * EvernoteService resolves target notebook from thought metadata
         * (e.g. metadata.type, metadata.tags) using these keys; fallback to 'default'.
         */
        'notebook_mapping' => [
            'default' => env('EVERNOTE_NOTEBOOK_GUID_DEFAULT'),
            'idea' => env('EVERNOTE_NOTEBOOK_GUID_IDEA'),
            'task' => env('EVERNOTE_NOTEBOOK_GUID_TASK'),
        ],
    ],

    'postmark_inbound' => [
        'webhook_secret' => env('POSTMARK_INBOUND_WEBHOOK_SECRET'),
        'capture_address' => env('POSTMARK_INBOUND_CAPTURE_ADDRESS', ''),
        'log_emails' => env('POSTMARK_INBOUND_LOG_EMAILS', false),
    ],

    'jira' => [
        'enabled' => filter_var(env('JIRA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'default_days' => (int) env('JIRA_SYNC_DAYS', 14),
        'scheduled_sync_days' => (int) env('JIRA_SCHEDULED_SYNC_DAYS', 1),
    ],
];

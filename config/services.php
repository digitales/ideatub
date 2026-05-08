<?php

return [
    'mcp' => [
        'access_key' => env('MCP_ACCESS_KEY'),
    ],
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'embedding_max_input_chars' => (int) env('OPENROUTER_EMBEDDING_MAX_INPUT_CHARS', 24000),
        'embedding_timeout_seconds' => (int) env('OPENROUTER_EMBEDDING_TIMEOUT_SECONDS', 45),
        'embedding_connect_timeout_seconds' => (int) env('OPENROUTER_EMBEDDING_CONNECT_TIMEOUT_SECONDS', 10),
        'embedding_chars_per_token' => (float) env('OPENROUTER_EMBEDDING_CHARS_PER_TOKEN', 2.0),
        'embedding_token_safety_margin' => (float) env('OPENROUTER_EMBEDDING_TOKEN_SAFETY_MARGIN', 0.75),
        'metadata_model' => env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini'),
        'metadata_timeout_seconds' => (int) env('OPENROUTER_METADATA_TIMEOUT_SECONDS', 45),
        'chat_timeout_seconds' => (int) env('OPENROUTER_CHAT_TIMEOUT_SECONDS', 60),
        'chat_connect_timeout_seconds' => (int) env('OPENROUTER_CHAT_CONNECT_TIMEOUT_SECONDS', 15),
        'chat_retry_times' => (int) env('OPENROUTER_CHAT_RETRY_TIMES', 2),
        'chat_retry_sleep_ms' => (int) env('OPENROUTER_CHAT_RETRY_SLEEP_MS', 250),
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

    'mail_sync' => [
        'enabled' => filter_var(env('MAIL_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'backfill_batch_size' => (int) env('MAIL_SYNC_BACKFILL_BATCH_SIZE', 50),
        'incremental_batch_size' => (int) env('MAIL_SYNC_INCREMENTAL_BATCH_SIZE', 25),
        /** Seconds for Fastmail JMAP HTTP reads (large Email/get payloads need more than Laravel's 30s default). */
        'jmap_timeout_seconds' => (int) env('MAIL_SYNC_JMAP_TIMEOUT', 600),
        'jmap_connect_timeout_seconds' => (int) env('MAIL_SYNC_JMAP_CONNECT_TIMEOUT', 30),
    ],

    'email_sender_policy' => [
        'enabled' => filter_var(env('EMAIL_SENDER_POLICY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'jira' => [
        'enabled' => filter_var(env('JIRA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'default_days' => (int) env('JIRA_SYNC_DAYS', 14),
        'scheduled_sync_days' => (int) env('JIRA_SCHEDULED_SYNC_DAYS', 1),
    ],

    'demo_mode' => [
        'enabled' => filter_var(env('DEMO_MODE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consolidated build source window
    |--------------------------------------------------------------------------
    |
    | Consolidated working memory includes only thoughts with created_at within
    | this many days (rolling window from "now"). Incremental builds keep their
    | own shorter hot window; tune via WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS.
    |
    */

    'consolidation_window_days' => (int) env('WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Compaction-primary evidence assembly
    |--------------------------------------------------------------------------
    |
    | When enabled, working memory builds compose from compactions, prior
    | canonical memory, and uncompacted raw thoughts only — not the full corpus.
    | Incremental jobs skip LLM compose when there is no delta.
    |
    */

    'compaction_primary' => filter_var(env('WORKING_MEMORY_COMPACTION_PRIMARY', true), FILTER_VALIDATE_BOOL),

    'uncompacted_thought_limit' => (int) env('WORKING_MEMORY_UNCOMPACTED_THOUGHT_LIMIT', 20),

    'external_protect_days' => (int) env('WORKING_MEMORY_EXTERNAL_PROTECT_DAYS', 14),

    'require_uuid_project_scope_key_for_source_labels' => ['elixirr-sync'],

    'dedupe_enabled' => filter_var(env('WORKING_MEMORY_DEDUPE_ENABLED', true), FILTER_VALIDATE_BOOL),

    'dedupe_nightly_days' => (int) env('WORKING_MEMORY_DEDUPE_NIGHTLY_DAYS', 30),

    'dedupe_volatile_patterns' => [
        '/^#+\s*working memory\s*$/i',
        '/^last updated:/i',
        '/^scope:/i',
        '/^\(?.*refreshed at.*\)?\s*$/i',
    ],

    'import_rate_per_minute' => (int) env('WORKING_MEMORY_IMPORT_RATE_PER_MINUTE', 50),

    /*
    |--------------------------------------------------------------------------
    | Working memory sync guardrails (capture/upsert)
    |--------------------------------------------------------------------------
    |
    | These controls protect against runaway sync loops and oversized/low-value
    | updates. Limits are intentionally configurable so operators can tune by
    | environment. Set a value to 0 to disable that specific limit.
    |
    */

    'sync_guardrails_enabled' => filter_var(env('WORKING_MEMORY_SYNC_GUARDRAILS_ENABLED', true), FILTER_VALIDATE_BOOL),
    'sync_min_interval_seconds' => (int) env('WORKING_MEMORY_SYNC_MIN_INTERVAL_SECONDS', 0),
    'sync_monthly_budget_tokens' => (int) env('WORKING_MEMORY_SYNC_MONTHLY_BUDGET_TOKENS', 0),
    'sync_max_content_chars' => (int) env('WORKING_MEMORY_SYNC_MAX_CONTENT_CHARS', 65535),
    'sync_min_delta_ratio' => (float) env('WORKING_MEMORY_SYNC_MIN_DELTA_RATIO', 0.0),
    'sync_token_chars_per_token' => (int) env('WORKING_MEMORY_SYNC_TOKEN_CHARS_PER_TOKEN', 4),

    'insights_model_enabled' => env('WORKING_MEMORY_INSIGHTS_MODEL_ENABLED', false),

    'authoring_enabled' => env('WORKING_MEMORY_AUTHORING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Authoring mode
    |--------------------------------------------------------------------------
    |
    | judgment_first: structure + Source Notes when evidence exists; citations
    | optional on other sections. strict: legacy per-bullet citation enforcement.
    |
    */

    'authoring_mode' => env('WORKING_MEMORY_AUTHORING_MODE', 'judgment_first'),

    'structure_required_sections' => [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ],

    'citation_min_coverage' => (float) env('WORKING_MEMORY_CITATION_MIN_COVERAGE', 0),

    'citation_required_sections' => env('WORKING_MEMORY_AUTHORING_MODE', 'judgment_first') === 'strict'
        ? [
            'Current Focus',
            'Active Priorities',
            'Recent Changes',
            'Open Questions',
            'Risks / Blockers',
            'Next Actions',
            'Latest Signals',
            'Source Notes',
        ]
        : [],

    'upsert_validate_sections' => filter_var(env('WORKING_MEMORY_UPSERT_VALIDATE_SECTIONS', false), FILTER_VALIDATE_BOOL),
    'authoring_model' => env('WORKING_MEMORY_AUTHORING_MODEL', 'openrouter/auto'),

    'authoring_composer_model' => env('WORKING_MEMORY_COMPOSER_MODEL', env('WORKING_MEMORY_AUTHORING_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini'))),
    'authoring_composer_temperature' => (float) env('WORKING_MEMORY_COMPOSER_TEMPERATURE', 0.2),
    'authoring_meeting_compaction_model' => env('WORKING_MEMORY_MEETING_COMPACTION_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
    'authoring_meeting_compaction_temperature' => (float) env('WORKING_MEMORY_MEETING_COMPACTION_TEMPERATURE', 0.2),
    'digest_window_days' => (int) env('WORKING_MEMORY_DIGEST_WINDOW_DAYS', 7),
    'digest_min_thoughts' => (int) env('WORKING_MEMORY_DIGEST_MIN_THOUGHTS', 3),
    'authoring_digest_model' => env('WORKING_MEMORY_DIGEST_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
    'authoring_digest_temperature' => (float) env('WORKING_MEMORY_DIGEST_TEMPERATURE', 0.2),
    'research_synth_min_thoughts' => (int) env('WORKING_MEMORY_RESEARCH_SYNTH_MIN_THOUGHTS', 8),
    'research_synth_freshness_hours' => (int) env('WORKING_MEMORY_RESEARCH_SYNTH_FRESHNESS_HOURS', 168),
    'authoring_research_model' => env('WORKING_MEMORY_RESEARCH_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
    'authoring_research_temperature' => (float) env('WORKING_MEMORY_RESEARCH_TEMPERATURE', 0.2),
    'authoring_max_prompt_input_chars' => (int) env('WORKING_MEMORY_MAX_PROMPT_INPUT_CHARS', 60000),

    /*
    |--------------------------------------------------------------------------
    | Composer chat completion token budget
    |--------------------------------------------------------------------------
    |
    | Working-memory compose uses researchFromPromptCompletion (not research.max_tokens).
    | When the provider returns finish_reason=length, one retry uses composer_max_tokens_length_retry.
    |
    */

    'composer_max_tokens' => (int) env('WORKING_MEMORY_COMPOSER_MAX_TOKENS', 4096),
    'composer_max_tokens_length_retry' => (int) env('WORKING_MEMORY_COMPOSER_MAX_TOKENS_LENGTH_RETRY', 8192),

    /*
    |--------------------------------------------------------------------------
    | LLM JSON decode failure logging
    |--------------------------------------------------------------------------
    |
    | When an LLM returns output that LlmJsonDecoder cannot parse, jobs log a
    | warning. Set log_llm_decode_failure_preview to true temporarily in
    | production to attach a truncated raw_preview field (PII-sensitive).
    |
    */

    'log_llm_decode_failure_preview' => (bool) env('WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW', false),
    'llm_decode_failure_preview_max_chars' => (int) env('WORKING_MEMORY_LLM_DECODE_FAILURE_PREVIEW_MAX_CHARS', 800),

    /*
    |--------------------------------------------------------------------------
    | Meeting refresh delay (seconds)
    |--------------------------------------------------------------------------
    |
    | When a meeting thought is captured, ThoughtObserver dispatches both a
    | SynthesizeMeetingCompactionJob and a RefreshWorkingMemoryIncremental.
    | Async queue workers may execute them in any order, so the refresh would
    | otherwise risk running before the compaction is persisted (missing it in
    | the evidence pack). This delay gives the compaction a head start.
    |
    | A value of 0 disables the delay (useful for sync queues / tests).
    |
    */

    'meeting_refresh_delay_seconds' => (int) env('WORKING_MEMORY_MEETING_REFRESH_DELAY_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Incremental refresh scope job timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Each affected scope is refreshed in its own queue job (dispatched from
    | RefreshWorkingMemoryIncremental). Set high enough for one OpenRouter compose
    | call (chat timeout + retries). Worker --timeout must be >= this value.
    |
    */

    'incremental_scope_job_timeout_seconds' => (int) env('WORKING_MEMORY_INCREMENTAL_SCOPE_JOB_TIMEOUT_SECONDS', 600),

    'compaction_retention' => [
        'meeting' => (int) env('WORKING_MEMORY_RETAIN_MEETING', 50),
        'weekly-digest' => (int) env('WORKING_MEMORY_RETAIN_WEEKLY_DIGEST', 12),
        'topic-digest' => (int) env('WORKING_MEMORY_RETAIN_TOPIC_DIGEST', 24),
        'research-synth' => (int) env('WORKING_MEMORY_RETAIN_RESEARCH_SYNTH', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compaction validator gate
    |--------------------------------------------------------------------------
    |
    | When enabled, CompactionVersionWriter runs WorkingMemoryOutputValidator
    | against the compaction's structured_sections + references using the
    | per-subtype required-sections map. If validation hard-fails, persistence
    | is aborted and a log warning is emitted.
    |
    | When disabled (the default), validation still runs but hard-fails only
    | log a warning; the compaction is persisted regardless. This is the
    | "observation mode" introduced before compaction prompts emit citations.
    |
    */

    'compaction_validation_enforced' => (bool) env('WORKING_MEMORY_COMPACTION_VALIDATION_ENFORCED', false),

    /*
    |--------------------------------------------------------------------------
    | Auto-rebuild on project thought changes
    |--------------------------------------------------------------------------
    |
    | When enabled per project, thought saves enqueue WorkingMemoryRebuildJob which
    | synthesizes working memory via LLM and upserts with an auto-rebuild label.
    |
    */

    'auto_rebuild_source_label_prefix' => env('WORKING_MEMORY_AUTO_REBUILD_SOURCE_LABEL_PREFIX', 'auto-rebuild'),

    'auto_rebuild_debounce_minutes' => (int) env('WORKING_MEMORY_AUTO_REBUILD_DEBOUNCE_MINUTES', 30),

    'auto_rebuild_thought_limit' => (int) env('WORKING_MEMORY_AUTO_REBUILD_THOUGHT_LIMIT', 20),

    'auto_rebuild_model' => env('WORKING_MEMORY_AUTO_REBUILD_MODEL', 'claude-sonnet-4-20250514'),

    'auto_rebuild_max_tokens' => (int) env('WORKING_MEMORY_AUTO_REBUILD_MAX_TOKENS', 2000),

];

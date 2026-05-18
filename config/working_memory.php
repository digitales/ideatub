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

    'external_protect_days' => (int) env('WORKING_MEMORY_EXTERNAL_PROTECT_DAYS', 14),

    'import_rate_per_minute' => (int) env('WORKING_MEMORY_IMPORT_RATE_PER_MINUTE', 50),

    'insights_model_enabled' => env('WORKING_MEMORY_INSIGHTS_MODEL_ENABLED', false),

    'authoring_enabled' => env('WORKING_MEMORY_AUTHORING_ENABLED', false),
    'citation_min_coverage' => (float) env('WORKING_MEMORY_CITATION_MIN_COVERAGE', 1.00),
    'citation_required_sections' => [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ],
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

];

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
    'authoring_max_prompt_input_chars' => (int) env('WORKING_MEMORY_MAX_PROMPT_INPUT_CHARS', 60000),

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

];

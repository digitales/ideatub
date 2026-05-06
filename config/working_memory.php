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

];

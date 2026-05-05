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

];

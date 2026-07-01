<?php

return [
    'file_upload' => env('FEATURE_FILE_UPLOAD', false),
    'working_memory_ui' => env('FEATURE_WORKING_MEMORY_UI', false),
    'working_memory_insights' => env('FEATURE_WORKING_MEMORY_INSIGHTS', false),
    'working_memory_ai_authored' => env('FEATURE_WORKING_MEMORY_AI_AUTHORED', false),
    'attention_pulse' => env('FEATURE_ATTENTION_PULSE', false),
    'memory_graph_local' => env('FEATURE_MEMORY_GRAPH_LOCAL', false),
    'memory_graph_project' => env('FEATURE_MEMORY_GRAPH_PROJECT', true),
    'memory_graph_tag' => env('FEATURE_MEMORY_GRAPH_TAG', false),
    'memory_graph_semantic' => env('FEATURE_MEMORY_GRAPH_SEMANTIC', false),
    'memory_graph_vault' => env('FEATURE_MEMORY_GRAPH_VAULT', false),
    'memory_graph_suggestions' => env('FEATURE_MEMORY_GRAPH_SUGGESTIONS', false),
];

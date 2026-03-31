<?php

return [
    /*
     * v1 has no rate-limit. When needed, set to true and implement throttling
     * (e.g. by user_id in cache) in ResearchService::runResearchForIdea.
     */
    'rate_limit_enabled' => env('RESEARCH_RATE_LIMIT_ENABLED', false),

    /*
     * Path to the research prompt template file (Markdown or plain text).
     * Placeholders: {{idea}}, {{existing_research}}. Override via RESEARCH_PROMPT_PATH.
     */
    'prompt_path' => env('RESEARCH_PROMPT_PATH', resource_path('prompts/research.md')),

    /*
     * Max tokens for the research completion (longer prompts need more room for the brief).
     * Override via RESEARCH_MAX_TOKENS.
     */
    'max_tokens' => env('RESEARCH_MAX_TOKENS', 2048),

    /*
     * Hard cap for queued/running research runs per user. Set to 0 to disable.
     */
    'max_active_runs_per_user' => env('RESEARCH_MAX_ACTIVE_RUNS_PER_USER', 25),
];

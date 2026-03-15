<?php

return [
    /*
     * v1 has no rate-limit. When needed, set to true and implement throttling
     * (e.g. by user_id in cache) in ResearchService::runResearchForIdea.
     */
    'rate_limit_enabled' => env('RESEARCH_RATE_LIMIT_ENABLED', false),
];

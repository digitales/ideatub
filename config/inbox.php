<?php

use App\Services\Inbox\Generators\JiraFollowUpInboxGenerator;
use App\Services\Inbox\Generators\MeetingActionInboxGenerator;
use App\Services\Inbox\Generators\NeglectedIdeaInboxGenerator;
use App\Services\Inbox\Generators\StaleProjectMemoryGenerator;
use App\Services\Inbox\Generators\WeeklyRevisitInboxGenerator;
use App\Services\Inbox\Generators\WorkingMemoryFallbackGenerator;

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum new inbox items per user per generation run
    |--------------------------------------------------------------------------
    */
    'max_new_items_per_user_per_run' => (int) env('INBOX_MAX_NEW_PER_RUN', 5),

    /*
    |--------------------------------------------------------------------------
    | Generator classes (run in this order)
    |--------------------------------------------------------------------------
    |
    | Each class must implement App\Services\Inbox\Contracts\InboxGenerator
    | and be resolvable from the container.
    |
    */
    'generators' => [
        WeeklyRevisitInboxGenerator::class,
        NeglectedIdeaInboxGenerator::class,
        WorkingMemoryFallbackGenerator::class,
        StaleProjectMemoryGenerator::class,
        MeetingActionInboxGenerator::class,
        JiraFollowUpInboxGenerator::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox grouping (bulk triage)
    |--------------------------------------------------------------------------
    |
    | When a user has at least group_minimum_count pending items of the same
    | generator_type, they render as a collapsed inbox group pinned above
    | paginated single-item cards.
    |
    */
    'group_minimum_count' => 2,

    'group_bulk_actions' => [
        'default' => ['done_all'],
        'import_completed' => ['ok_all'],
        'email_sender_review' => ['allow_all', 'ignore_all'],
    ],

    'group_confirm_actions' => [
        'allow_all',
        'ignore_all',
    ],

    'group_subtitles' => [
        'wm_fallback' => ':count scopes in fallback authoring',
        'stale_project_memory' => ':count project scopes need memory refresh',
        'import_completed' => ':count imports completed',
        'email_sender_review' => ':count senders to review',
        'meeting_action' => ':count meeting action items',
        'jira_follow_up' => ':count Jira follow-ups',
        'neglected_idea' => ':count neglected ideas',
        'weekly_revisit' => ':count weekly revisit prompts',
        'default' => ':count items',
    ],
];

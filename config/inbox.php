<?php

use App\Services\Inbox\Generators\NeglectedIdeaInboxGenerator;
use App\Services\Inbox\Generators\WeeklyRevisitInboxGenerator;

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
    ],
];

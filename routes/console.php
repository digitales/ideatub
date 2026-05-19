<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('research:reconcile-comment-unread-counts')->dailyAt('03:30');

Schedule::command('thoughts:extract-untagged')->hourly();
Schedule::command('inbox:generate')->hourly();
Schedule::command('jira:sync-all')->hourly()->when(fn () => config('services.jira.enabled', true));
Schedule::command('mail:sync-all')->hourly()->when(fn () => config('services.mail_sync.enabled', true));
Schedule::command('imports:prune-expired-batches')->dailyAt('03:00');
Schedule::command('working-memory:consolidate')->dailyAt('02:45');
Schedule::command('working-memory:dedupe --days=30')
    ->dailyAt('03:15')
    ->when(fn () => config('working_memory.dedupe_enabled', true));
Schedule::command('compactions:digest')->hourly();
Schedule::command('compactions:research')->dailyAt('04:15');

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('thoughts:extract-untagged')->hourly();
Schedule::command('inbox:generate')->hourly();
Schedule::command('jira:sync-all')->hourly()->when(fn () => config('services.jira.enabled', true));

<?php

namespace App\Console\Commands;

use App\Jobs\SyncUserJiraActivity;
use App\Models\User;
use Illuminate\Console\Command;

class SyncAllJiraActivityCommand extends Command
{
    protected $signature = 'jira:sync-all';

    protected $description = 'Dispatch Jira sync for every user with Jira credentials. Intended for hourly schedule. No-op when Jira is disabled.';

    public function handle(): int
    {
        if (! config('services.jira.enabled', true)) {
            $this->comment('Jira integration is disabled. Skipping sync.');

            return self::SUCCESS;
        }

        $userIds = User::query()
            ->has('jiraCredential')
            ->pluck('id');

        if ($userIds->isEmpty()) {
            $this->info('No users with Jira credentials. Nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            SyncUserJiraActivity::dispatch($userId, 0);
        }

        $this->info(sprintf('Dispatched Jira sync for %d user(s).', $userIds->count()));

        return self::SUCCESS;
    }
}

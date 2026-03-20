<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailAccountIncremental;
use App\Models\MailAccount;
use Illuminate\Console\Command;

class SyncAllMailAccountsCommand extends Command
{
    protected $signature = 'mail:sync-all';

    protected $description = 'Dispatch incremental mail sync for active mail accounts. Intended for hourly schedule. No-op when mail sync is disabled.';

    public function handle(): int
    {
        if (! config('services.mail_sync.enabled', true)) {
            $this->comment('Mail sync is disabled. Skipping sync.');

            return self::SUCCESS;
        }

        $accountIds = MailAccount::query()
            ->where('status', 'active')
            ->where('settings_json->sync_enabled', true)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            $this->info('No active mail accounts with sync enabled. Nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($accountIds as $accountId) {
            SyncMailAccountIncremental::dispatch($accountId);
        }

        $this->info(sprintf('Dispatched mail sync for %d account(s).', $accountIds->count()));

        return self::SUCCESS;
    }
}

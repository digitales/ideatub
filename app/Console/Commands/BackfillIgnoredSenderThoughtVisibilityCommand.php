<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileIgnoredSenderThoughtVisibility;
use App\Models\EmailSenderRule;
use Illuminate\Console\Command;

class BackfillIgnoredSenderThoughtVisibilityCommand extends Command
{
    protected $signature = 'email:backfill-ignored-sender-thought-visibility';

    protected $description = 'Queue reconciliation jobs to align thought stream visibility with ignored sender rules.';

    public function handle(): int
    {
        if (! config('services.email_sender_policy.enabled')) {
            $this->comment('Email sender policy is disabled. Skipping backfill.');

            return self::SUCCESS;
        }

        $pairs = EmailSenderRule::query()
            ->where('action', EmailSenderRule::ACTION_IGNORE)
            ->select(['user_id', 'sender_email'])
            ->distinct()
            ->orderBy('user_id')
            ->orderBy('sender_email')
            ->get();

        $queued = 0;
        foreach ($pairs as $row) {
            ReconcileIgnoredSenderThoughtVisibility::dispatch($row->user_id, $row->sender_email);
            $queued++;
        }

        $this->info("Queued {$queued} reconciliation job(s) for ignored sender rules.");

        return self::SUCCESS;
    }
}

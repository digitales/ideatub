<?php

namespace App\Jobs;

use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Models\MailSyncRun;
use App\Services\Email\EmailImportService;
use App\Services\Fastmail\FastmailConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMailAccountIncremental implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public int $timeout = 600;

    public function __construct(
        public readonly int $mailAccountId
    ) {}

    public function handle(FastmailConnector $connector, EmailImportService $emailImportService): void
    {
        $account = MailAccount::find($this->mailAccountId);
        if ($account === null) {
            return;
        }

        $run = MailSyncRun::create([
            'mail_account_id' => $account->id,
            'run_type' => 'incremental',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $batch = $connector->fetchIncrementalBatch($account);

            foreach ($batch['messages'] ?? [] as $message) {
                $emailImportService->importMessage($account, $message, $run);
            }

            $account->provider_checkpoint_json = $batch['next_checkpoint'] ?? null;
            $account->last_synced_at = now();
            $account->last_successful_sync_at = now();
            $account->save();

            $run->status = 'completed';
            $run->finished_at = now();
            $run->stats_json = [
                'message_count' => count($batch['messages'] ?? []),
            ];
            $run->save();
        } catch (InvalidMailAccountCredentialsException $exception) {
            $account->status = 'needs_reauth';
            $account->last_synced_at = now();
            $account->save();

            $run->status = 'failed';
            $run->finished_at = now();
            $run->error_summary = $exception->getMessage();
            $run->save();

            return;
        } catch (Throwable $throwable) {
            $account->last_synced_at = now();
            $account->save();

            $run->status = 'failed';
            $run->finished_at = now();
            $run->error_summary = $throwable->getMessage();
            $run->save();

            throw $throwable;
        }
    }
}

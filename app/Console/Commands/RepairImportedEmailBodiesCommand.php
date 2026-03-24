<?php

namespace App\Console\Commands;

use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Services\Email\ImportedEmailBodyRepairService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RepairImportedEmailBodiesCommand extends Command
{
    private const BATCH_SIZE = 25;

    private const ROW_DELAY_MICROSECONDS = 50_000;

    private const BATCH_GAP_MICROSECONDS = 150_000;

    protected $signature = 'emails:repair-imported-bodies
                            {--dry-run : List repairs without writing to the database}
                            {--limit=100 : Maximum number of rows to process}
                            {--mail-account-id= : Only process rows for this mail account id}';

    protected $description = 'Refetch and repair missing body text for eligible Fastmail imported email rows.';

    public function handle(ImportedEmailBodyRepairService $repairService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $mailAccountId = $this->option('mail-account-id');
        $mailAccountId = is_string($mailAccountId) ? trim($mailAccountId) : '';

        if ($mailAccountId !== '' && ! ctype_digit($mailAccountId)) {
            $this->error('The --mail-account-id option must be a numeric mail account id.');

            return self::INVALID;
        }

        $mailAccountId = $mailAccountId === '' ? null : (int) $mailAccountId;

        $query = $this->eligibleQuery($mailAccountId);
        $totalEligible = (clone $query)->count();

        if ($totalEligible === 0) {
            $this->info('No eligible imported emails found.');
            $this->reportCounts(0, 0, 0);

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d eligible row(s); processing up to %d.', $totalEligible, $limit));

        if ($dryRun) {
            $this->comment('Dry run: no database writes from this command.');
        }

        $rows = $query
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        $batches = $rows->chunk(self::BATCH_SIZE);
        $batchCount = $batches->count();

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $row) {
                try {
                    $result = $repairService->repair($row, $dryRun);

                    if ($result['skipped'] ?? false) {
                        $skipped++;
                    } elseif ($dryRun && ($result['would_repair'] ?? false)) {
                        $repaired++;
                    } elseif (! $dryRun && ($result['repaired'] ?? false)) {
                        $repaired++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('Failed on imported email id %s: %s', $row->getKey(), $e->getMessage()));
                }

                usleep(self::ROW_DELAY_MICROSECONDS);
            }

            if ($batchIndex < $batchCount - 1) {
                usleep(self::BATCH_GAP_MICROSECONDS);
            }
        }

        $this->reportCounts($repaired, $skipped, $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reportCounts(int $repaired, int $skipped, int $failed): void
    {
        $this->info(sprintf('Repaired: %d', $repaired));
        $this->info(sprintf('Skipped: %d', $skipped));
        $this->info(sprintf('Failed: %d', $failed));
    }

    /**
     * @return Builder<ImportedEmail>
     */
    private function eligibleQuery(?int $mailAccountId): Builder
    {
        $query = ImportedEmail::query()
            ->where('provider', 'fastmail')
            ->where(function (Builder $q): void {
                $q->whereNull('body_text')
                    ->orWhereRaw("TRIM(REPLACE(REPLACE(REPLACE(COALESCE(body_text, ''), '\t', ''), '\n', ''), '\r', '')) = ''");
            })
            ->where('processing_status', '!=', 'filtered')
            ->where(function (Builder $q): void {
                $q->whereNull('rule_action')
                    ->orWhere('rule_action', '!=', EmailSenderRule::ACTION_IGNORE);
            })
            ->whereHas('mailAccount');

        if ($mailAccountId !== null) {
            $query->where('mail_account_id', $mailAccountId);
        }

        return $query;
    }
}

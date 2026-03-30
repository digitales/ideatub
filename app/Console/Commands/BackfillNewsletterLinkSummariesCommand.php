<?php

namespace App\Console\Commands;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\ResetNewsletterResearchState;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillNewsletterLinkSummariesCommand extends Command
{
    protected $signature = 'email-research:backfill-link-summaries
                            {--dry-run : Show counts and actions without dispatching jobs}
                            {--requeue : Clear existing newsletter linkage state before dispatching}
                            {--user-id= : Limit scanning to one user}
                            {--limit= : Stop after N scanned rows}
                            {--stored-type= : Limit to imported or captured rows}';

    protected $description = 'Queue ProcessExtraEmailResearch for stored newsletter rows to backfill link summaries.';

    private int $scanned = 0;

    private int $queued = 0;

    private int $requeued = 0;

    private int $skipped = 0;

    private int $missingResearchThought = 0;

    public function handle(): int
    {
        $storedType = $this->option('stored-type');
        if ($storedType !== null && $storedType !== '' && ! in_array($storedType, ['imported', 'captured'], true)) {
            $this->error('The --stored-type option must be "imported" or "captured".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $requeue = (bool) $this->option('requeue');
        $userIdOption = $this->option('user-id');
        $userIdFilter = ($userIdOption !== null && $userIdOption !== '') ? (int) $userIdOption : null;
        $limitOption = $this->option('limit');
        $limit = ($limitOption !== null && $limitOption !== '') ? (int) $limitOption : null;

        $scanImported = $storedType === null || $storedType === '' || $storedType === 'imported';
        $scanCaptured = $storedType === null || $storedType === '' || $storedType === 'captured';

        if ($scanImported) {
            $this->scanImportedEmails($userIdFilter, $limit, $dryRun, $requeue);
        }

        if ($scanCaptured && ($limit === null || $this->scanned < $limit)) {
            $this->scanCapturedInboundEmails($userIdFilter, $limit, $dryRun, $requeue);
        }

        $this->line('Scanned: '.$this->scanned);
        $this->line('Queued: '.$this->queued);
        $this->line('Requeued: '.$this->requeued);
        $this->line('Skipped: '.$this->skipped);
        $this->line('Missing research thought: '.$this->missingResearchThought);

        if ($dryRun) {
            $this->comment('Dry run: no database writes or job dispatches were performed.');
        }

        return self::SUCCESS;
    }

    private function scanImportedEmails(?int $userIdFilter, ?int $limit, bool $dryRun, bool $requeue): void
    {
        $query = ImportedEmail::query()
            ->whereNotNull('thought_id')
            ->orderBy('id');

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        foreach ($query->cursor() as $row) {
            if ($limit !== null && $this->scanned >= $limit) {
                break;
            }

            $this->processStoredEmailRow($row, $dryRun, $requeue);
        }
    }

    private function scanCapturedInboundEmails(?int $userIdFilter, ?int $limit, bool $dryRun, bool $requeue): void
    {
        $query = CapturedInboundEmail::query()
            ->whereNotNull('thought_id')
            ->orderBy('id');

        if ($userIdFilter !== null) {
            $query->where('user_id', $userIdFilter);
        }

        foreach ($query->cursor() as $row) {
            if ($limit !== null && $this->scanned >= $limit) {
                break;
            }

            $this->processStoredEmailRow($row, $dryRun, $requeue);
        }
    }

    private function processStoredEmailRow(ImportedEmail|CapturedInboundEmail $row, bool $dryRun, bool $requeue): void
    {
        $this->scanned++;

        $userId = (int) $row->user_id;
        $emailThoughtId = (string) $row->thought_id;

        $emailThought = $this->resolveEligibleEmailThought($emailThoughtId, $userId);
        if ($emailThought === null) {
            $this->skipped++;

            return;
        }

        if ($requeue) {
            $this->requeued++;
            if ($dryRun) {
                return;
            }

            app(ResetNewsletterResearchState::class)->reset($emailThought, $row);

            if ($row instanceof ImportedEmail) {
                ProcessExtraEmailResearch::dispatch(importedEmailId: $row->id);
            } else {
                ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $row->id);
            }

            return;
        }

        if ($row->research_thought_id === null || (string) $row->research_thought_id === '') {
            $this->skipped++;

            return;
        }

        $researchThoughtId = (string) $row->research_thought_id;

        $research = $this->resolveEligibleResearchThought($researchThoughtId, $userId);
        if ($research === null) {
            $this->missingResearchThought++;

            return;
        }

        $this->queued++;
        if ($dryRun) {
            return;
        }

        if ($row instanceof ImportedEmail) {
            ProcessExtraEmailResearch::dispatch(importedEmailId: $row->id);
        } else {
            ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $row->id);
        }
    }

    private function resolveEligibleResearchThought(string $researchThoughtId, int $userId): ?Thought
    {
        return Thought::query()
            ->whereKey($researchThoughtId)
            ->where('user_id', $userId)
            ->where(function (Builder $q): void {
                $q->where(function (Builder $q2): void {
                    $q2->matchingCanonicalMetadataType('research');
                })->orWhere('source', 'research');
            })
            ->first();
    }

    private function resolveEligibleEmailThought(string $emailThoughtId, int $userId): ?Thought
    {
        return Thought::query()
            ->whereKey($emailThoughtId)
            ->where('user_id', $userId)
            ->matchingCanonicalSourceType('email')
            ->first();
    }
}

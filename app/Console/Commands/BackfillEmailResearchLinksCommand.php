<?php

namespace App\Console\Commands;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\EmailNewsletterResearchService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillEmailResearchLinksCommand extends Command
{
    protected $signature = 'email-research:backfill-links {--dry-run : Show counts and changes without writing}';

    protected $description = 'Backfill email_thought_id, email_subject, and email_sender on research thoughts from imported and captured inbound email rows.';

    private int $scanned = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $conflicted = 0;

    public function handle(EmailNewsletterResearchService $newsletterResearch): int
    {
        $dryRun = (bool) $this->option('dry-run');

        ImportedEmail::query()
            ->whereNotNull('thought_id')
            ->whereNotNull('research_thought_id')
            ->orderBy('id')
            ->each(function (ImportedEmail $row) use ($newsletterResearch, $dryRun): void {
                $this->processStoredEmailRow($row, $newsletterResearch, $dryRun);
            });

        CapturedInboundEmail::query()
            ->whereNotNull('thought_id')
            ->whereNotNull('research_thought_id')
            ->orderBy('id')
            ->each(function (CapturedInboundEmail $row) use ($newsletterResearch, $dryRun): void {
                $this->processStoredEmailRow($row, $newsletterResearch, $dryRun);
            });

        $this->line('Scanned: '.$this->scanned);
        $this->line('Updated: '.$this->updated);
        $this->line('Skipped: '.$this->skipped);
        $this->line('Conflicted: '.$this->conflicted);

        if ($dryRun) {
            $this->comment('Dry run: no database writes were performed.');
        }

        return self::SUCCESS;
    }

    private function processStoredEmailRow(
        ImportedEmail|CapturedInboundEmail $row,
        EmailNewsletterResearchService $newsletterResearch,
        bool $dryRun,
    ): void {
        $this->scanned++;

        $userId = (int) $row->user_id;
        $emailThoughtId = (string) $row->thought_id;
        $researchThoughtId = (string) $row->research_thought_id;

        $research = $this->resolveEligibleResearchThought($researchThoughtId, $userId);
        if ($research === null) {
            $this->skipped++;

            return;
        }

        $emailThought = $this->resolveEligibleEmailThought($emailThoughtId, $userId);
        if ($emailThought === null) {
            $this->skipped++;

            return;
        }

        if ((int) $research->user_id !== $userId) {
            $this->skipped++;

            return;
        }

        $payload = $newsletterResearch->linkageFieldsForStoredEmail($row, $emailThought);
        if ($payload === null) {
            $this->skipped++;

            return;
        }

        if ($this->hasLinkageConflict($research, $payload)) {
            $this->conflicted++;

            return;
        }

        if (! $this->needsLinkageWrite($research, $payload)) {
            $this->skipped++;

            return;
        }

        $this->updated++;
        if ($dryRun) {
            return;
        }

        $sourceMetadata = is_array($research->source_metadata) ? $research->source_metadata : [];
        $metadata = is_array($research->metadata) ? $research->metadata : [];

        foreach (['email_thought_id', 'email_subject', 'email_sender'] as $key) {
            $sourceMetadata[$key] = $payload[$key];
            $metadata[$key] = $payload[$key];
        }

        $research->update([
            'source_metadata' => $sourceMetadata,
            'metadata' => $metadata,
        ]);
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

    /**
     * @param  array{email_thought_id: string, email_subject: string, email_sender: string}  $payload
     */
    private function hasLinkageConflict(Thought $research, array $payload): bool
    {
        $candidate = strtolower(trim((string) $payload['email_thought_id']));

        foreach (['source_metadata', 'metadata'] as $attr) {
            $raw = data_get($research->{$attr}, 'email_thought_id');
            if ($raw === null || $raw === '') {
                continue;
            }
            $existing = strtolower(trim((string) $raw));
            if ($existing !== '' && $existing !== $candidate) {
                return true;
            }
        }

        foreach (['email_subject', 'email_sender'] as $key) {
            $want = (string) $payload[$key];
            foreach (['source_metadata', 'metadata'] as $attr) {
                $raw = data_get($research->{$attr}, $key);
                if ($raw === null || $raw === '') {
                    continue;
                }
                if ((string) $raw !== $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{email_thought_id: string, email_subject: string, email_sender: string}  $payload
     */
    private function needsLinkageWrite(Thought $research, array $payload): bool
    {
        foreach (['email_thought_id', 'email_subject', 'email_sender'] as $key) {
            $want = (string) $payload[$key];
            $src = data_get($research->source_metadata, $key);
            $meta = data_get($research->metadata, $key);
            if ((string) ($src ?? '') !== $want || (string) ($meta ?? '') !== $want) {
                return true;
            }
        }

        return false;
    }
}

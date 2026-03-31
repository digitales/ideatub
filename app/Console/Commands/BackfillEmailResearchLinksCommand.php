<?php

namespace App\Console\Commands;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\EmailNewsletterResearchService;
use App\Support\ThoughtTypeNavigation;
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

    /**
     * @var array<string, true>
     */
    private array $seenResearchThoughtIds = [];

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

        Thought::query()
            ->matchingCanonicalSourceType('email')
            ->matchingCanonicalMetadataType('research')
            ->orderBy('created_at')
            ->each(function (Thought $research) use ($newsletterResearch, $dryRun): void {
                $this->processLegacyEmailSourcedResearchThought($research, $newsletterResearch, $dryRun);
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

        if ($this->markSeenResearchThought($research->id)) {
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

        $this->persistResearchLinkage($research, $payload);
        $this->persistBackLinks($emailThought, $row, $research->id);
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

        return $this->isCanonicalEmailSource($research->source)
            || ! $this->isCanonicalResearchMetadataType(data_get($research->metadata, 'type'));
    }

    private function processLegacyEmailSourcedResearchThought(
        Thought $research,
        EmailNewsletterResearchService $newsletterResearch,
        bool $dryRun,
    ): void {
        if ($this->markSeenResearchThought($research->id)) {
            return;
        }

        $emailThoughtId = trim((string) data_get($research->metadata, 'idea_id'));
        if ($emailThoughtId === '') {
            return;
        }

        $emailThought = $this->resolveEligibleEmailThought($emailThoughtId, (int) $research->user_id);
        if ($emailThought === null) {
            return;
        }

        $row = $this->resolveStoredEmailRowForThought($emailThought);
        if ($row === null) {
            return;
        }

        $this->scanned++;

        $payload = $newsletterResearch->linkageFieldsForStoredEmail($row, $emailThought);
        if ($payload === null) {
            $this->skipped++;

            return;
        }

        if ($this->hasLinkageConflict($research, $payload) || $this->hasBackLinkConflict($emailThought, $row, $research->id)) {
            $this->conflicted++;

            return;
        }

        if (! $this->needsLinkageWrite($research, $payload) && ! $this->needsBackLinkWrite($emailThought, $row, $research->id)) {
            $this->skipped++;

            return;
        }

        $this->updated++;
        if ($dryRun) {
            return;
        }

        $this->persistResearchLinkage($research, $payload);
        $this->persistBackLinks($emailThought, $row, $research->id);
    }

    /**
     * @param  array{email_thought_id: string, email_subject: string, email_sender: string}  $payload
     */
    private function persistResearchLinkage(Thought $research, array $payload): void
    {
        $sourceMetadata = is_array($research->source_metadata) ? $research->source_metadata : [];
        $metadata = is_array($research->metadata) ? $research->metadata : [];
        $metadata['type'] = 'research';

        foreach (['email_thought_id', 'email_subject', 'email_sender'] as $key) {
            $sourceMetadata[$key] = $payload[$key];
            $metadata[$key] = $payload[$key];
        }

        $updates = [
            'source_metadata' => $sourceMetadata,
            'metadata' => $metadata,
        ];

        if ($this->isCanonicalEmailSource($research->source)) {
            $updates['source'] = 'research';
        }

        $research->update($updates);
    }

    private function persistBackLinks(Thought $emailThought, ImportedEmail|CapturedInboundEmail $row, string $researchThoughtId): void
    {
        $emailMeta = is_array($emailThought->source_metadata) ? $emailThought->source_metadata : [];
        $emailMeta['research_thought_id'] = $researchThoughtId;
        $emailThought->update(['source_metadata' => $emailMeta]);

        if ((string) ($row->research_thought_id ?? '') !== $researchThoughtId) {
            $row->update(['research_thought_id' => $researchThoughtId]);
        }
    }

    private function resolveStoredEmailRowForThought(Thought $emailThought): ImportedEmail|CapturedInboundEmail|null
    {
        $imported = $emailThought->importedEmail();
        if ($imported !== null) {
            return $imported;
        }

        $capturedId = data_get($emailThought->source_metadata, 'captured_inbound_email_id');
        if ($capturedId !== null && (string) $capturedId !== '') {
            $captured = CapturedInboundEmail::query()
                ->where('user_id', $emailThought->user_id)
                ->find($capturedId);
            if ($captured !== null) {
                return $captured;
            }
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $emailThought->user_id)
            ->where('thought_id', $emailThought->id)
            ->first();
    }

    private function hasBackLinkConflict(
        Thought $emailThought,
        ImportedEmail|CapturedInboundEmail $row,
        string $researchThoughtId,
    ): bool {
        $candidate = $this->normalizeId($researchThoughtId);
        foreach ([
            data_get($emailThought->source_metadata, 'research_thought_id'),
            $row->research_thought_id,
        ] as $raw) {
            $existing = $this->normalizeId($raw);
            if ($existing !== null && $existing !== $candidate) {
                return true;
            }
        }

        return false;
    }

    private function needsBackLinkWrite(
        Thought $emailThought,
        ImportedEmail|CapturedInboundEmail $row,
        string $researchThoughtId,
    ): bool {
        return $this->normalizeId(data_get($emailThought->source_metadata, 'research_thought_id')) !== $this->normalizeId($researchThoughtId)
            || $this->normalizeId($row->research_thought_id) !== $this->normalizeId($researchThoughtId);
    }

    private function isCanonicalEmailSource(?string $source): bool
    {
        return in_array(mb_strtolower(trim((string) $source)), ['email', 'emails'], true);
    }

    private function isCanonicalResearchMetadataType(mixed $value): bool
    {
        return in_array(
            mb_strtolower(trim((string) $value)),
            ThoughtTypeNavigation::storedValuesForCollection('research'),
            true
        );
    }

    private function markSeenResearchThought(?string $researchThoughtId): bool
    {
        $id = $this->normalizeId($researchThoughtId);
        if ($id === null) {
            return false;
        }

        if (isset($this->seenResearchThoughtIds[$id])) {
            return true;
        }

        $this->seenResearchThoughtIds[$id] = true;

        return false;
    }

    private function normalizeId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $id = strtolower(trim((string) $value));

        return $id === '' ? null : $id;
    }
}

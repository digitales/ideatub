<?php

namespace App\Services\Email;

use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Services\Fastmail\FastmailConnector;
use Carbon\CarbonImmutable;

class ImportedEmailBodyRepairService
{
    public function __construct(
        private readonly FastmailConnector $connector,
        private readonly EmailBodyCleanupService $bodyCleanup,
    ) {}

    /**
     * Refetch provider body for a single Fastmail imported row with missing body text.
     *
     * @return array{
     *     repaired: bool,
     *     skipped: bool,
     *     dry_run: bool,
     *     would_repair?: bool,
     *     skip_reason?: string
     * }
     */
    public function repair(ImportedEmail $row, bool $dryRun = false): array
    {
        if (($row->provider ?? '') !== 'fastmail') {
            return $this->skippedResponse('not_fastmail', $dryRun);
        }

        if (! $this->isBodyMissing($row->body_text)) {
            return $this->skippedResponse('body_present', $dryRun);
        }

        if ($row->processing_status === 'filtered') {
            return $this->skippedResponse('filtered', $dryRun);
        }

        if (($row->rule_action ?? '') === EmailSenderRule::ACTION_IGNORE) {
            return $this->skippedResponse('rule_ignore', $dryRun);
        }

        $account = $row->mailAccount;
        if ($account === null) {
            return $this->skippedResponse('mail_account_missing', $dryRun);
        }

        $message = $this->connector->fetchMessageById($account, $row->provider_message_id);
        if ($message === null) {
            return $this->skippedResponse('fetch_failed', $dryRun);
        }

        $cleanBody = $this->bodyCleanup->clean($message->bodyText);
        if ($cleanBody === '') {
            return $this->skippedResponse('cleaned_body_empty', $dryRun);
        }

        $fingerprint = hash('sha256', implode('|', [
            $row->provider_message_id,
            $row->subject ?? '',
            $cleanBody,
        ]));

        if ($dryRun) {
            return [
                'repaired' => false,
                'skipped' => false,
                'dry_run' => true,
                'would_repair' => true,
            ];
        }

        $meta = $row->processing_metadata_json ?? [];
        $existingRepair = $meta['body_repair'] ?? [];
        $meta['body_repair'] = array_merge(
            is_array($existingRepair) ? $existingRepair : [],
            ['repaired_at' => CarbonImmutable::now()->toIso8601String()]
        );

        $updates = [
            'body_text' => $cleanBody,
            'content_fingerprint' => $fingerprint,
            'processing_metadata_json' => $meta,
        ];

        ImportedEmail::query()
            ->whereKey($row->getKey())
            ->update($updates);

        $row->forceFill($updates);
        $row->syncOriginalAttributes(array_keys($updates));

        return [
            'repaired' => true,
            'skipped' => false,
            'dry_run' => false,
            'would_repair' => false,
        ];
    }

    private function isBodyMissing(?string $body): bool
    {
        return $body === null || trim($body) === '';
    }

    /**
     * @return array{repaired: bool, skipped: bool, dry_run: bool, skip_reason: string, would_repair: bool}
     */
    private function skippedResponse(string $reason, bool $dryRun): array
    {
        return [
            'repaired' => false,
            'skipped' => true,
            'dry_run' => $dryRun,
            'skip_reason' => $reason,
            'would_repair' => false,
        ];
    }
}

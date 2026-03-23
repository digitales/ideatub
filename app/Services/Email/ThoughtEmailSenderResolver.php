<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Support\ThoughtTypeNavigation;

/**
 * Resolves the normalized mailbox for an email-sourced thought, or null when unsafe/unknown.
 */
class ThoughtEmailSenderResolver
{
    public function __construct(
        private readonly EmailSenderRuleService $senderRuleService,
    ) {}

    public function resolve(Thought $thought): ?string
    {
        if (! $this->isEmailThought($thought)) {
            return null;
        }

        $meta = $thought->source_metadata ?? [];

        if (array_key_exists('imported_email_id', $meta)) {
            $row = $this->importedEmailFromMetadata($thought);
            if ($row !== null) {
                return $this->normalizedFromImportedRow($row, $thought);
            }
        }

        if (array_key_exists('captured_inbound_email_id', $meta)) {
            $row = $this->capturedInboundFromMetadata($thought);
            if ($row !== null) {
                return $this->normalizedFromCapturedRow($row, $thought);
            }
        }

        $imported = $thought->importedEmail();
        if ($imported !== null) {
            return $this->normalizedFromImportedRow($imported, $thought);
        }

        $captured = CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->where('thought_id', $thought->id)
            ->first();
        if ($captured !== null) {
            return $this->normalizedFromCapturedRow($captured, $thought);
        }

        return null;
    }

    private function isEmailThought(Thought $thought): bool
    {
        $source = mb_strtolower(trim((string) ($thought->source ?? '')));

        return in_array($source, ThoughtTypeNavigation::storedValuesForCollection('email'), true);
    }

    private function importedEmailFromMetadata(Thought $thought): ?ImportedEmail
    {
        $id = data_get($thought->source_metadata, 'imported_email_id');
        if ($id === null || $id === '') {
            return null;
        }

        return ImportedEmail::query()
            ->where('user_id', $thought->user_id)
            ->find($id);
    }

    private function capturedInboundFromMetadata(Thought $thought): ?CapturedInboundEmail
    {
        $id = data_get($thought->source_metadata, 'captured_inbound_email_id');
        if ($id === null || $id === '') {
            return null;
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->find($id);
    }

    private function normalizedFromImportedRow(ImportedEmail $row, Thought $thought): ?string
    {
        $raw = $this->rawSenderFromImportedEmail($row, $thought);
        $normalized = $this->senderRuleService->normalizeSender($raw);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizedFromCapturedRow(CapturedInboundEmail $row, Thought $thought): ?string
    {
        $raw = $this->rawSenderFromCapturedInboundEmail($row, $thought);
        $normalized = $this->senderRuleService->normalizeSender($raw);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Precedence: rule_email → formatted first from_json participant → thought metadata fallback.
     *
     * @return non-empty-string|string
     */
    private function rawSenderFromImportedEmail(ImportedEmail $row, Thought $thought): string
    {
        if (is_string($row->rule_email) && trim($row->rule_email) !== '') {
            return trim($row->rule_email);
        }

        $fromJson = $this->formatFirstFromJson($row->from_json ?? []);
        if ($fromJson !== '') {
            return $fromJson;
        }

        $fromParticipant = $this->firstFromRoleMailbox(data_get($thought->source_metadata, 'participants', []) ?? []);
        if ($fromParticipant !== '') {
            return $fromParticipant;
        }

        return '';
    }

    /**
     * Precedence: rule_email → sender_email → thought source_metadata `from`.
     *
     * @return non-empty-string|string
     */
    private function rawSenderFromCapturedInboundEmail(CapturedInboundEmail $row, Thought $thought): string
    {
        if (is_string($row->rule_email) && trim($row->rule_email) !== '') {
            return trim($row->rule_email);
        }

        if (is_string($row->sender_email) && trim($row->sender_email) !== '') {
            return trim($row->sender_email);
        }

        $from = data_get($thought->source_metadata, 'from');
        if (is_string($from) && trim($from) !== '') {
            return trim($from);
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $fromJson
     */
    private function formatFirstFromJson(array $fromJson): string
    {
        $first = $fromJson[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        $email = trim((string) ($first['email'] ?? ''));
        $name = isset($first['name']) ? trim((string) $first['name']) : '';

        if ($email === '') {
            return '';
        }

        return $name !== ''
            ? sprintf('%s <%s>', $name, $email)
            : $email;
    }

    /**
     * @param  array<int, mixed>  $participants
     */
    private function firstFromRoleMailbox(array $participants): string
    {
        foreach ($participants as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['role'] ?? '') !== 'from') {
                continue;
            }

            $email = trim((string) ($entry['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $name = isset($entry['name']) ? trim((string) $entry['name']) : '';

            return $name !== ''
                ? sprintf('%s <%s>', $name, $email)
                : $email;
        }

        return '';
    }
}

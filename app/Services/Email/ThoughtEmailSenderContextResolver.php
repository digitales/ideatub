<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\Thought;

final class ThoughtEmailSenderContextResolver
{
    public function __construct(
        private readonly EmailSenderRuleService $senderRuleService,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     sender_available: bool,
     *     stored_email_type: string|null,
     *     stored_email_id: int|null,
     *     raw_sender: string|null,
     *     normalized_sender: string|null,
     *     rule: EmailSenderRule|null
     * }
     */
    public function resolve(Thought $thought): array
    {
        if ($thought->source !== 'email' || ! config('services.email_sender_policy.enabled')) {
            return $this->emptyContext(enabled: false);
        }

        $sourceMetadata = $thought->source_metadata ?? [];
        $importedEmail = $thought->importedEmail();

        if ($importedEmail !== null) {
            return $this->buildContext(
                $thought,
                'imported_email',
                $importedEmail->id,
                $this->resolveImportedEmailSender($importedEmail, $sourceMetadata),
            );
        }

        $capturedInboundEmail = $this->capturedInboundEmail($thought);

        if ($capturedInboundEmail !== null) {
            return $this->buildContext(
                $thought,
                'captured_inbound_email',
                $capturedInboundEmail->id,
                $this->resolveCapturedInboundSender($capturedInboundEmail, $sourceMetadata),
            );
        }

        return $this->buildContext(
            $thought,
            null,
            null,
            $this->resolveSenderFromMetadata($sourceMetadata),
        );
    }

    private function buildContext(
        Thought $thought,
        ?string $storedEmailType,
        ?int $storedEmailId,
        string $rawSender,
    ): array {
        $normalizedSender = $this->senderRuleService->normalizeSender($rawSender);

        if ($normalizedSender === '') {
            return [
                'enabled' => true,
                'sender_available' => false,
                'stored_email_type' => $storedEmailType,
                'stored_email_id' => $storedEmailId,
                'raw_sender' => $rawSender !== '' ? $rawSender : null,
                'normalized_sender' => null,
                'rule' => null,
            ];
        }

        return [
            'enabled' => true,
            'sender_available' => true,
            'stored_email_type' => $storedEmailType,
            'stored_email_id' => $storedEmailId,
            'raw_sender' => $rawSender,
            'normalized_sender' => $normalizedSender,
            'rule' => EmailSenderRule::query()
                ->where('user_id', $thought->user_id)
                ->where('sender_email', $normalizedSender)
                ->first(),
        ];
    }

    private function emptyContext(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'sender_available' => false,
            'stored_email_type' => null,
            'stored_email_id' => null,
            'raw_sender' => null,
            'normalized_sender' => null,
            'rule' => null,
        ];
    }

    private function capturedInboundEmail(Thought $thought): ?CapturedInboundEmail
    {
        $capturedInboundEmailId = data_get($thought->source_metadata, 'captured_inbound_email_id');

        if ($capturedInboundEmailId !== null) {
            $capturedInboundEmail = CapturedInboundEmail::query()
                ->where('user_id', $thought->user_id)
                ->find($capturedInboundEmailId);

            if ($capturedInboundEmail !== null) {
                return $capturedInboundEmail;
            }
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->where('thought_id', $thought->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    private function resolveImportedEmailSender(ImportedEmail $row, array $sourceMetadata): string
    {
        $ruleEmail = trim((string) ($row->rule_email ?? ''));
        if ($ruleEmail !== '') {
            return $ruleEmail;
        }

        $formattedFrom = $this->formatFirstParticipantEntry($row->from_json);
        if ($formattedFrom !== '') {
            return $formattedFrom;
        }

        return $this->resolveSenderFromMetadata($sourceMetadata);
    }

    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    private function resolveCapturedInboundSender(CapturedInboundEmail $row, array $sourceMetadata): string
    {
        $ruleEmail = trim((string) ($row->rule_email ?? ''));
        if ($ruleEmail !== '') {
            return $ruleEmail;
        }

        $senderEmail = trim((string) ($row->sender_email ?? ''));
        if ($senderEmail !== '') {
            return $senderEmail;
        }

        return $this->resolveSenderFromMetadata($sourceMetadata);
    }

    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    private function resolveSenderFromMetadata(array $sourceMetadata): string
    {
        $from = $sourceMetadata['from'] ?? null;

        if (is_string($from)) {
            return trim($from);
        }

        return $this->formatParticipantEntries($from);
    }

    private function formatParticipantEntries(mixed $entries): string
    {
        if (! is_array($entries) || $entries === []) {
            return '';
        }

        $parts = collect($entries)
            ->map(function (mixed $entry): ?string {
                if (! is_array($entry)) {
                    return null;
                }

                $email = trim((string) ($entry['email'] ?? ''));
                $name = trim((string) ($entry['name'] ?? ''));

                if ($email === '') {
                    return $name !== '' ? $name : null;
                }

                return $name !== ''
                    ? sprintf('%s <%s>', $name, $email)
                    : $email;
            })
            ->filter()
            ->values()
            ->all();

        return $parts === [] ? '' : implode(', ', $parts);
    }

    private function formatFirstParticipantEntry(mixed $entries): string
    {
        if (! is_array($entries) || $entries === []) {
            return '';
        }

        $firstEntry = $entries[0] ?? null;
        if (! is_array($firstEntry)) {
            return '';
        }

        $email = trim((string) ($firstEntry['email'] ?? ''));
        if ($email === '') {
            return '';
        }

        $name = trim((string) ($firstEntry['name'] ?? ''));

        return $name !== ''
            ? sprintf('%s <%s>', $name, $email)
            : $email;
    }
}

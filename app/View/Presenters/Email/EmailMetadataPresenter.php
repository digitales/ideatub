<?php

namespace App\View\Presenters\Email;

use App\Models\ImportedEmail;
use App\Models\Thought;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Carbon;

/**
 * Read-only shaping of email metadata for the thought detail sidebar.
 */
final class EmailMetadataPresenter
{
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Thought $thought,
        private readonly ?ImportedEmail $importedEmail,
    ) {}

    public static function from(Thought $thought, ?ImportedEmail $importedEmail): self
    {
        return new self($thought, $importedEmail);
    }

    public function subject(): ?string
    {
        $v = $this->importedEmail?->subject ?? data_get($this->sourceMetadata(), 'subject');
        $scalar = $this->displayScalar($v);
        if ($scalar === null) {
            return null;
        }

        try {
            return $this->demoText($scalar, 'email_subject');
        } catch (\Throwable) {
            return 'Demo content hidden';
        }
    }

    public function direction(): ?string
    {
        $v = $this->importedEmail?->direction ?? data_get($this->sourceMetadata(), 'direction');

        return $this->displayScalar($v);
    }

    public function provider(): ?string
    {
        $v = $this->importedEmail?->provider ?? data_get($this->sourceMetadata(), 'provider');

        return $this->displayScalar($v);
    }

    public function mailboxName(): ?string
    {
        $v = $this->importedEmail?->provider_mailbox_name ?? data_get($this->sourceMetadata(), 'provider_mailbox_name');

        return $this->displayScalar($v);
    }

    public function mailboxId(): ?string
    {
        $v = $this->importedEmail?->provider_mailbox_id ?? data_get($this->sourceMetadata(), 'provider_mailbox_id');

        return $this->displayScalar($v);
    }

    public function threadId(): ?string
    {
        $v = $this->importedEmail?->provider_thread_id ?? data_get($this->sourceMetadata(), 'provider_thread_id');

        return $this->displayScalar($v);
    }

    public function accountEmail(): ?string
    {
        if ($this->importedEmail !== null && $this->importedEmail->relationLoaded('mailAccount')) {
            $fromAccount = $this->importedEmail->mailAccount?->account_email;
            $fromScalar = $this->displayScalar($fromAccount);
            if ($fromScalar !== null) {
                return $fromScalar;
            }
        }

        $meta = data_get($this->sourceMetadata(), 'account_email');

        return $this->displayScalar($meta);
    }

    public function fromLine(): ?string
    {
        return $this->formatParticipants(
            $this->importedEmail?->from_json ?? data_get($this->sourceMetadata(), 'from', [])
        );
    }

    public function toLine(): ?string
    {
        return $this->formatParticipants(
            $this->importedEmail?->to_json ?? data_get($this->sourceMetadata(), 'to', [])
        );
    }

    public function ccLine(): ?string
    {
        return $this->formatParticipants(
            $this->importedEmail?->cc_json ?? data_get($this->sourceMetadata(), 'cc', [])
        );
    }

    public function sentDisplay(): ?string
    {
        $sentAt = $this->importedEmail?->sent_at ?? data_get($this->sourceMetadata(), 'sent_at');

        return $this->formatDateTimeDisplay($sentAt);
    }

    public function receivedDisplay(): ?string
    {
        $receivedAt = $this->importedEmail?->received_at ?? data_get($this->sourceMetadata(), 'received_at');

        return $this->formatDateTimeDisplay($receivedAt);
    }

    private function formatParticipants(mixed $entries): ?string
    {
        if (! is_array($entries) || $entries === []) {
            return null;
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

                return $name !== '' ? $name.' <'.$email.'>' : $email;
            })
            ->filter()
            ->values()
            ->all();

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function formatDateTimeDisplay(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDayDateTimeString();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function sourceMetadata(): array
    {
        return $this->thought->source_metadata ?? [];
    }

    private function displayScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_scalar($value)) {
            $asString = (string) $value;

            return $asString === '' ? null : $asString;
        }

        return null;
    }
}

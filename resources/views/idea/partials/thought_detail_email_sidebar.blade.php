@php
    $sourceMetadata = $thought->source_metadata ?? [];
    $subject = $importedEmail?->subject ?? data_get($sourceMetadata, 'subject');
    $direction = $importedEmail?->direction ?? data_get($sourceMetadata, 'direction');
    $provider = $importedEmail?->provider ?? data_get($sourceMetadata, 'provider');
    $sentAt = $importedEmail?->sent_at ?? data_get($sourceMetadata, 'sent_at');
    $receivedAt = $importedEmail?->received_at ?? data_get($sourceMetadata, 'received_at');
    $threadId = $importedEmail?->provider_thread_id ?? data_get($sourceMetadata, 'provider_thread_id');
    $mailboxName = $importedEmail?->provider_mailbox_name ?? data_get($sourceMetadata, 'provider_mailbox_name');
    $mailboxId = $importedEmail?->provider_mailbox_id ?? data_get($sourceMetadata, 'provider_mailbox_id');
    $accountEmail = $importedEmail?->mailAccount?->account_email ?? data_get($sourceMetadata, 'account_email');
    $fromEntries = $importedEmail?->from_json ?? data_get($sourceMetadata, 'from', []);
    $toEntries = $importedEmail?->to_json ?? data_get($sourceMetadata, 'to', []);
    $ccEntries = $importedEmail?->cc_json ?? data_get($sourceMetadata, 'cc', []);

    $formatParticipants = static function (mixed $entries): ?string {
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
    };

    $fromLine = $formatParticipants($fromEntries);
    $toLine = $formatParticipants($toEntries);
    $ccLine = $formatParticipants($ccEntries);
    $sentLine = $sentAt instanceof \Illuminate\Support\Carbon ? $sentAt->toDayDateTimeString() : (is_string($sentAt) ? $sentAt : null);
    $receivedLine = $receivedAt instanceof \Illuminate\Support\Carbon ? $receivedAt->toDayDateTimeString() : (is_string($receivedAt) ? $receivedAt : null);
@endphp

<aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Email metadata</p>

    <div class="space-y-3 text-[13px] text-slate-brand">
        @if ($subject)
            <p><span class="font-medium text-deep-indigo">Subject: {{ $subject }}</span></p>
        @endif
        @if ($direction)
            <p><span class="font-medium text-deep-indigo">Direction: {{ $direction }}</span></p>
        @endif
        @if ($fromLine)
            <p><span class="font-medium text-deep-indigo">From: {{ $fromLine }}</span></p>
        @endif
        @if ($toLine)
            <p><span class="font-medium text-deep-indigo">To: {{ $toLine }}</span></p>
        @endif
        @if ($ccLine)
            <p><span class="font-medium text-deep-indigo">Cc: {{ $ccLine }}</span></p>
        @endif
        @if ($sentLine)
            <p><span class="font-medium text-deep-indigo">Sent: {{ $sentLine }}</span></p>
        @endif
        @if ($receivedLine)
            <p><span class="font-medium text-deep-indigo">Received: {{ $receivedLine }}</span></p>
        @endif
        @if ($provider)
            <p><span class="font-medium text-deep-indigo">Provider: {{ $provider }}</span></p>
        @endif
        @if ($mailboxName)
            <p><span class="font-medium text-deep-indigo">Mailbox: {{ $mailboxName }}</span></p>
        @elseif ($mailboxId)
            <p><span class="font-medium text-deep-indigo">Mailbox ID: {{ $mailboxId }}</span></p>
        @endif
        @if ($threadId)
            <p><span class="font-medium text-deep-indigo">Thread ID: {{ $threadId }}</span></p>
        @endif
        @if ($accountEmail)
            <p><span class="font-medium text-deep-indigo">Account: {{ $accountEmail }}</span></p>
        @endif
    </div>

    @include('idea.partials.thought_detail_sender_rule_card', ['senderRuleContext' => $senderRuleContext])
</aside>

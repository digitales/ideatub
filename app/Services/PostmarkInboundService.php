<?php

namespace App\Services;

use App\Models\Thought;
use App\Models\UnmatchedInboundEmail;
use App\Models\User;
use App\Models\UserInboundAddress;
use Carbon\Carbon;

class PostmarkInboundService
{
    public function __construct(
        private OpenRouterService $openRouter
    ) {}

    /**
     * Process an inbound Postmark webhook payload: create a Thought for a matched user
     * or store in unmatched_inbound_emails for later analysis.
     */
    public function process(array $payload): void
    {
        $messageId = $payload['MessageID'] ?? '';
        $messageId = is_string($messageId) ? trim($messageId) : '';

        $bodyText = $this->extractBodyText($payload);
        if ($bodyText === '') {
            return;
        }

        $from = $this->normaliseEmail($payload['From'] ?? $payload['FromFull']['Email'] ?? '');
        if ($from === '') {
            $this->storeUnmatched($payload, $bodyText, $messageId);

            return;
        }

        $user = $this->resolveUser($from);
        if ($user === null) {
            $this->storeUnmatched($payload, $bodyText, $messageId);

            return;
        }

        if ($this->thoughtAlreadyExists($user->id, $messageId)) {
            return;
        }

        $attachmentNames = $this->extractAttachmentNames($payload);
        $sourceMetadata = [
            'message_id' => $messageId,
            'from' => $payload['From'] ?? $from,
            'subject' => $payload['Subject'] ?? null,
            'date' => $payload['Date'] ?? null,
            'attachment_names' => $attachmentNames,
        ];
        if (isset($payload['To'])) {
            $sourceMetadata['to'] = $payload['To'];
        }
        if (isset($payload['ReplyTo'])) {
            $sourceMetadata['reply_to'] = $payload['ReplyTo'];
        }

        $embedding = $this->openRouter->embed($bodyText);
        $metadata = Thought::normalizeMetadataTags($this->openRouter->extractMetadata($bodyText));

        Thought::create([
            'content' => $bodyText,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => $sourceMetadata,
        ]);
    }

    private function extractBodyText(array $payload): string
    {
        $text = $payload['TextBody'] ?? '';
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }
        $html = $payload['HtmlBody'] ?? '';
        if (is_string($html) && trim($html) !== '') {
            return trim(strip_tags($html));
        }

        return '';
    }

    private function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Resolve user by primary email or inbound address. Prefer users.email match, then lowest user_id.
     */
    private function resolveUser(string $normalisedFrom): ?User
    {
        $byPrimary = User::query()->whereRaw('LOWER(email) = ?', [$normalisedFrom])->orderBy('id')->get();
        if ($byPrimary->isNotEmpty()) {
            return $byPrimary->first();
        }
        $inbound = UserInboundAddress::query()->where('email', $normalisedFrom)->orderBy('user_id')->first();
        if ($inbound !== null) {
            return $inbound->user;
        }

        return null;
    }

    private function thoughtAlreadyExists(string $userId, string $messageId): bool
    {
        return Thought::query()
            ->where('user_id', $userId)
            ->where('source', 'email')
            ->where('source_metadata->message_id', $messageId)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function extractAttachmentNames(array $payload): array
    {
        $attachments = $payload['Attachments'] ?? [];
        if (! is_array($attachments)) {
            return [];
        }
        $names = [];
        foreach ($attachments as $att) {
            if (isset($att['Name']) && is_string($att['Name']) && $att['Name'] !== '') {
                $names[] = $att['Name'];
            }
        }

        return $names;
    }

    private function storeUnmatched(array $payload, string $bodyText, string $messageId): void
    {
        if ($messageId !== '' && UnmatchedInboundEmail::query()->where('message_id', $messageId)->exists()) {
            return;
        }
        if ($messageId === '') {
            $messageId = 'no-message-id-'.uniqid('', true);
        }

        $fromEmail = $this->normaliseEmail($payload['From'] ?? $payload['FromFull']['Email'] ?? '');
        $toEmail = null;
        if (isset($payload['ToFull'][0]['Email']) && is_string($payload['ToFull'][0]['Email'])) {
            $toEmail = $payload['ToFull'][0]['Email'];
        } elseif (isset($payload['To']) && is_string($payload['To'])) {
            $toEmail = $payload['To'];
        }

        $receivedAt = null;
        if (isset($payload['Date']) && is_string($payload['Date'])) {
            try {
                $receivedAt = Carbon::parse($payload['Date']);
            } catch (\Throwable) {
                // leave null
            }
        }

        $minimalPayload = [
            'From' => $payload['From'] ?? null,
            'Subject' => $payload['Subject'] ?? null,
            'Date' => $payload['Date'] ?? null,
        ];

        UnmatchedInboundEmail::create([
            'message_id' => $messageId,
            'from_email' => $fromEmail !== '' ? $fromEmail : 'unknown',
            'to_email' => $toEmail,
            'subject' => isset($payload['Subject']) && is_string($payload['Subject']) ? mb_substr($payload['Subject'], 0, 1024) : null,
            'body_text' => $bodyText,
            'received_at' => $receivedAt,
            'payload_json' => $minimalPayload,
        ]);
    }
}

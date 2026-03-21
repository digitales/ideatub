<?php

namespace App\Services;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\Thought;
use App\Models\UnmatchedInboundEmail;
use App\Models\User;
use App\Models\UserInboundAddress;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\EmailReviewInboxService;
use App\Services\Email\EmailSenderRuleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PostmarkInboundService
{
    public function __construct(
        private ThoughtCaptureService $captureService,
        private EmailSenderRuleService $senderRuleService,
        private EmailReviewInboxService $reviewInboxService,
        private EmailLinkExtractor $linkExtractor,
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

        if (! config('services.email_sender_policy.enabled')) {
            $this->processMatchedUserLegacy($payload, $user, $bodyText, $messageId, $from);

            return;
        }

        $this->processMatchedUserWithSenderPolicy($payload, $user, $bodyText, $messageId, $from);
    }

    private function processMatchedUserLegacy(
        array $payload,
        User $user,
        string $bodyText,
        string $messageId,
        string $from,
    ): void {
        if ($this->thoughtAlreadyExists($user->id, $messageId)) {
            return;
        }

        $attachmentNames = $this->extractAttachmentNames($payload);
        $sourceMetadata = $this->buildSourceMetadata($payload, $messageId, $from, $attachmentNames);
        $subject = isset($payload['Subject']) && is_string($payload['Subject']) ? $payload['Subject'] : '';
        $noChunking = $this->emailRequestsNoChunking($subject, $bodyText);

        $this->captureService->create([
            'content' => $bodyText,
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => $sourceMetadata,
            'no_chunking' => $noChunking,
        ]);
    }

    private function processMatchedUserWithSenderPolicy(
        array $payload,
        User $user,
        string $bodyText,
        string $messageId,
        string $from,
    ): void {
        $rawFrom = $this->rawFromString($payload, $from);
        $decision = $this->senderRuleService->resolveForUser($user, $rawFrom);

        if ($decision['action'] === EmailSenderRule::ACTION_IGNORE) {
            return;
        }

        $subject = isset($payload['Subject']) && is_string($payload['Subject']) ? $payload['Subject'] : '';
        $receivedAt = $this->parseReceivedAt($payload);
        $ruleEmail = $decision['normalized_sender'] !== ''
            ? $decision['normalized_sender']
            : null;
        $senderEmailStored = $ruleEmail ?? $from;
        $storedMessageId = $this->resolveStoredMessageId(
            $user,
            $messageId,
            $senderEmailStored,
            $subject,
            $bodyText,
            $receivedAt,
        );

        if ($this->inboundAlreadyProcessed($user->id, $storedMessageId)) {
            return;
        }

        $baseCaptured = [
            'user_id' => $user->id,
            'message_id' => $storedMessageId,
            'sender_email' => $senderEmailStored,
            'subject' => $subject !== '' ? mb_substr($subject, 0, 1024) : null,
            'body_text' => $bodyText,
            'received_at' => $receivedAt,
            'rule_action' => $decision['action'],
            'rule_email' => $ruleEmail,
        ];

        if ($decision['action'] === EmailSenderRule::ACTION_REVIEW) {
            DB::transaction(function () use ($payload, $user, $bodyText, $baseCaptured, $decision): void {
                $captured = CapturedInboundEmail::create(array_merge($baseCaptured, [
                    'thought_id' => null,
                    'research_thought_id' => null,
                    'review_inbox_item_id' => null,
                    'processing_status' => 'pending',
                    'processing_metadata_json' => null,
                ]));
                $html = isset($payload['HtmlBody']) && is_string($payload['HtmlBody']) ? $payload['HtmlBody'] : null;
                $links = $this->linkExtractor->extractFromContent($bodyText, $html);
                $captured->processing_metadata_json = [
                    'extracted_links' => $links,
                ];
                $captured->processing_status = 'review_queued';
                $captured->save();

                $inbox = $this->reviewInboxService->ensureForCapturedInboundEmail($user, $captured->fresh(), $decision['action']);
                $captured->review_inbox_item_id = $inbox->id;
                $captured->save();
            });

            return;
        }

        if ($decision['action'] === EmailSenderRule::ACTION_ALLOW) {
            DB::transaction(function () use ($payload, $user, $bodyText, $baseCaptured, $decision): void {
                $captured = CapturedInboundEmail::create(array_merge($baseCaptured, [
                    'thought_id' => null,
                    'research_thought_id' => null,
                    'review_inbox_item_id' => null,
                    'processing_status' => 'pending',
                    'processing_metadata_json' => null,
                ]));
                $capture = $this->captureService->create(
                    $this->senderPolicyThoughtOptions($payload, $user, $captured, $bodyText, $decision)
                );
                $thought = $capture['thought'] ?? $capture['root'] ?? null;
                if ($thought === null) {
                    throw new \RuntimeException('Thought capture did not return a thought.');
                }
                $captured->thought_id = $thought->id;
                $captured->processing_status = 'imported';
                $captured->save();
            });

            return;
        }

        if ($decision['action'] === EmailSenderRule::ACTION_EXTRA_PROCESS) {
            $captured = DB::transaction(function () use ($payload, $user, $bodyText, $baseCaptured, $decision): CapturedInboundEmail {
                $captured = CapturedInboundEmail::create(array_merge($baseCaptured, [
                    'thought_id' => null,
                    'research_thought_id' => null,
                    'review_inbox_item_id' => null,
                    'processing_status' => 'pending',
                    'processing_metadata_json' => null,
                ]));
                $capture = $this->captureService->create(
                    $this->senderPolicyThoughtOptions($payload, $user, $captured, $bodyText, $decision)
                );
                $thought = $capture['thought'] ?? $capture['root'] ?? null;
                if ($thought === null) {
                    throw new \RuntimeException('Thought capture did not return a thought.');
                }
                $captured->thought_id = $thought->id;
                $captured->processing_status = 'research_queued';
                $captured->save();

                return $captured->fresh();
            });

            try {
                $this->dispatchExtraEmailResearch($captured);
            } catch (Throwable $throwable) {
                $this->markResearchDispatchFailed($captured, $throwable);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSourceMetadata(array $payload, string $messageId, string $fromForMetadata, array $attachmentNames): array
    {
        $sourceMetadata = [
            'message_id' => $messageId,
            'from' => $payload['From'] ?? $fromForMetadata,
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

        return $sourceMetadata;
    }

    private function rawFromString(array $payload, string $fallbackNormalised): string
    {
        if (isset($payload['From']) && is_string($payload['From']) && trim($payload['From']) !== '') {
            return $payload['From'];
        }
        if (isset($payload['FromFull']['Email']) && is_string($payload['FromFull']['Email'])) {
            return $payload['FromFull']['Email'];
        }

        return $fallbackNormalised;
    }

    private function inboundAlreadyProcessed(string $userId, string $messageId): bool
    {
        if ($messageId !== '' && CapturedInboundEmail::query()
            ->where('user_id', $userId)
            ->where('message_id', $messageId)
            ->exists()) {
            return true;
        }

        return $this->thoughtAlreadyExists($userId, $messageId);
    }

    /**
     * @param  array{action: string, normalized_sender: string, rule_id: int|null, raw_sender: string}  $decision
     * @return array<string, mixed>
     */
    private function senderPolicyThoughtOptions(
        array $payload,
        User $user,
        CapturedInboundEmail $captured,
        string $bodyText,
        array $decision,
    ): array {
        $attachmentNames = $this->extractAttachmentNames($payload);
        $sourceMetadata = $this->buildSourceMetadata($payload, $captured->message_id, $captured->sender_email, $attachmentNames);
        $sourceMetadata['captured_inbound_email_id'] = $captured->id;
        $sourceMetadata['sender_rule_action'] = $decision['action'];
        if ($decision['action'] === EmailSenderRule::ACTION_EXTRA_PROCESS) {
            $sourceMetadata['newsletter_research'] = [
                'status' => 'research_queued',
            ];
        }

        return [
            'content' => $bodyText,
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => $sourceMetadata,
            'no_chunking' => true,
        ];
    }

    /**
     * Opt-out convention: subject or body contains [no-chunk] or [no_chunking] (case-insensitive).
     */
    private function emailRequestsNoChunking(string $subject, string $bodyText): bool
    {
        $haystack = $subject."\n".$bodyText;

        return stripos($haystack, '[no-chunk]') !== false || stripos($haystack, '[no_chunking]') !== false;
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

    protected function dispatchExtraEmailResearch(CapturedInboundEmail $captured): void
    {
        ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $captured->id);
    }

    private function markResearchDispatchFailed(CapturedInboundEmail $captured, Throwable $throwable): void
    {
        $metadata = $captured->processing_metadata_json ?? [];
        $metadata['research_dispatch'] = [
            'status' => 'failed',
            'message' => $throwable->getMessage(),
        ];

        $captured->processing_metadata_json = $metadata;
        $captured->processing_status = 'research_failed';
        $captured->save();

        if ($captured->thought_id !== null) {
            $thought = $captured->thought()->first();
            if ($thought !== null) {
                $thoughtMeta = $thought->source_metadata ?? [];
                $thoughtMeta['newsletter_research'] = [
                    'status' => 'research_failed',
                    'message' => $throwable->getMessage(),
                ];
                $thought->source_metadata = $thoughtMeta;
                $thought->save();
            }
        }
    }

    private function resolveStoredMessageId(
        User $user,
        string $messageId,
        string $senderEmail,
        string $subject,
        string $bodyText,
        ?Carbon $receivedAt,
    ): string {
        if ($messageId !== '') {
            return $messageId;
        }

        return 'postmark-fallback-'.hash('sha256', implode('|', [
            (string) $user->id,
            $senderEmail,
            $subject,
            $bodyText,
            $receivedAt?->toIso8601String() ?? '',
        ]));
    }

    private function parseReceivedAt(array $payload): ?Carbon
    {
        if (isset($payload['Date']) && is_string($payload['Date'])) {
            try {
                return Carbon::parse($payload['Date']);
            } catch (Throwable) {
                // leave null
            }
        }

        return null;
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
            } catch (Throwable) {
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

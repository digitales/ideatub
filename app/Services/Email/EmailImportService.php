<?php

namespace App\Services\Email;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\MailSyncRun;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmailImportService
{
    public function __construct(
        private readonly EmailBodyCleanupService $bodyCleanupService,
        private readonly ParticipantNormalizer $participantNormalizer,
        private readonly EmailFilterService $filterService,
        private readonly ThoughtCaptureService $thoughtCaptureService,
        private readonly EmailSenderRuleService $senderRuleService,
        private readonly EmailReviewInboxService $reviewInboxService,
        private readonly EmailLinkExtractor $linkExtractor,
        private readonly EmailThoughtStreamVisibilityService $streamVisibilityService,
    ) {}

    public function importMessage(
        MailAccount $account,
        NormalizedEmailMessage $message,
        ?MailSyncRun $syncRun = null,
    ): ?ImportedEmail {
        $user = $account->user;
        $policyForReceived = config('services.email_sender_policy.enabled') && $message->direction === 'received';

        $decision = null;
        if ($policyForReceived) {
            $rawSender = $this->formatRawSender($message->from);
            $decision = $this->senderRuleService->resolveForUser($user, $rawSender);
            if ($decision['action'] === EmailSenderRule::ACTION_IGNORE) {
                return null;
            }
        }

        $row = ImportedEmail::query()->firstOrCreate(
            [
                'mail_account_id' => $account->id,
                'provider_message_id' => $message->providerMessageId,
            ],
            [
                'user_id' => $account->user_id,
                'mail_sync_run_id' => $syncRun?->id,
                'provider' => $account->provider,
                'direction' => $message->direction,
                'processing_status' => 'pending',
            ]
        );

        if ($row->processing_status === 'filtered') {
            return $row;
        }

        if ($row->thought_id !== null || $row->thought_deleted_at !== null) {
            return $row;
        }

        if ($policyForReceived && $row->processing_status === 'review_queued' && $row->review_inbox_item_id !== null) {
            return $row->fresh();
        }

        try {
            $cleanBody = $this->bodyCleanupService->clean($message->bodyText);
            $participants = $this->participantNormalizer->normalize($message->from, $message->to, $message->cc);

            $senderPolicyAction = null;
            if ($policyForReceived && $decision !== null) {
                $senderPolicyAction = $decision['action'];
            }

            $filter = $this->filterService->evaluate($account, $message, $senderPolicyAction);

            $baseFill = [
                'user_id' => $account->user_id,
                'mail_sync_run_id' => $syncRun?->id,
                'provider' => $account->provider,
                'provider_thread_id' => $message->providerThreadId,
                'provider_mailbox_id' => $message->providerMailboxIds[0] ?? null,
                'provider_mailbox_name' => null,
                'direction' => $message->direction,
                'subject' => $message->subject,
                'from_json' => $message->from,
                'to_json' => $message->to,
                'cc_json' => $message->cc,
                'participants_json' => $participants,
                'sent_at' => $message->sentAt,
                'received_at' => $message->receivedAt,
                'body_text' => $filter['include'] ? $cleanBody : null,
                'content_fingerprint' => hash('sha256', implode('|', [
                    $message->providerMessageId,
                    $message->subject ?? '',
                    $cleanBody,
                ])),
                'processing_status' => $filter['include'] ? 'pending' : 'filtered',
                'failure_reason' => $filter['include'] ? null : $filter['reason'],
            ];

            if ($policyForReceived && $decision !== null) {
                $baseFill['rule_action'] = $decision['action'];
                $baseFill['rule_email'] = $decision['normalized_sender'] !== ''
                    ? $decision['normalized_sender']
                    : null;
            }

            $row->fill($baseFill);
            $row->save();

            if (! $filter['include']) {
                return $row->fresh();
            }

            if ($policyForReceived && $decision !== null && $decision['action'] === EmailSenderRule::ACTION_REVIEW) {
                return DB::transaction(function () use ($cleanBody, $decision, $row, $user): ImportedEmail {
                    $links = $this->linkExtractor->extractFromContent($cleanBody, null);
                    $row->processing_metadata_json = [
                        'extracted_links' => $links,
                    ];
                    $row->processing_status = 'review_queued';
                    $row->save();

                    $inbox = $this->reviewInboxService->ensureForImportedEmail($user, $row->fresh(), $decision['action']);
                    $row->review_inbox_item_id = $inbox->id;
                    $row->save();

                    return $row->fresh();
                });
            }

            if ($policyForReceived && $decision !== null && $decision['action'] === EmailSenderRule::ACTION_EXTRA_PROCESS) {
                $capture = $this->thoughtCaptureService->create($this->thoughtOptions($account, $row, $decision));

                $thought = $capture['thought'] ?? $capture['root'] ?? null;
                if ($thought === null) {
                    throw new \RuntimeException('Thought capture did not return a thought.');
                }

                $row->thought_id = $thought->id;
                if ($policyForReceived) {
                    $this->streamVisibilityService->applyToThought($thought, $user, $this->formatRawSender($message->from));
                }
                $row->processing_status = 'research_queued';
                $row->save();

                try {
                    $this->dispatchExtraEmailResearch($row);
                } catch (Throwable $throwable) {
                    $this->markResearchDispatchFailed($row, $throwable);

                    throw $throwable;
                }

                return $row->fresh();
            }

            $capture = $this->thoughtCaptureService->create($this->thoughtOptions($account, $row, $decision));

            $thought = $capture['thought'] ?? $capture['root'] ?? null;
            if ($thought === null) {
                throw new \RuntimeException('Thought capture did not return a thought.');
            }

            $row->thought_id = $thought->id;
            if ($policyForReceived) {
                $this->streamVisibilityService->applyToThought($thought, $user, $this->formatRawSender($message->from));
            }
            $row->processing_status = 'imported';
            $row->save();

            return $row->fresh();
        } catch (Throwable $throwable) {
            if ($row->thought_id !== null && $row->processing_status === 'research_failed') {
                $row->failure_count = (int) $row->failure_count + 1;
                $row->save();

                throw $throwable;
            }

            $row->fill([
                'user_id' => $account->user_id,
                'mail_sync_run_id' => $syncRun?->id,
                'provider' => $account->provider,
                'direction' => $message->direction,
                'failure_reason' => $throwable->getMessage(),
                'processing_status' => 'pending',
            ]);
            $row->failure_count = (int) $row->failure_count + 1;
            $row->save();

            throw $throwable;
        }
    }

    protected function dispatchExtraEmailResearch(ImportedEmail $row): void
    {
        ProcessExtraEmailResearch::dispatch($row->id);
    }

    private function markResearchDispatchFailed(ImportedEmail $row, Throwable $throwable): void
    {
        $metadata = $row->processing_metadata_json ?? [];
        $metadata['research_dispatch'] = [
            'status' => 'failed',
            'message' => $throwable->getMessage(),
        ];

        $row->processing_metadata_json = $metadata;
        $row->processing_status = 'research_failed';
        $row->failure_reason = $throwable->getMessage();
        $row->save();

        if ($row->thought_id !== null) {
            $thought = $row->thought()->first();
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

    /**
     * @param  array{action: string, normalized_sender: string, rule_id: int|null, raw_sender: string}|null  $decision
     * @return array<string, mixed>
     */
    private function thoughtOptions(MailAccount $account, ImportedEmail $row, ?array $decision): array
    {
        $meta = [
            'provider' => $row->provider,
            'mail_account_id' => $row->mail_account_id,
            'imported_email_id' => $row->id,
            'provider_message_id' => $row->provider_message_id,
            'provider_thread_id' => $row->provider_thread_id,
            'direction' => $row->direction,
            'subject' => $row->subject,
            'sent_at' => $row->sent_at?->toIso8601String(),
            'received_at' => $row->received_at?->toIso8601String(),
            'participants' => $row->participants_json,
            'provider_mailbox_name' => $row->provider_mailbox_name,
            'mail_sync_run_id' => $row->mail_sync_run_id,
        ];

        if ($decision !== null) {
            $meta['sender_rule_action'] = $decision['action'];
            if ($decision['action'] === EmailSenderRule::ACTION_EXTRA_PROCESS) {
                $meta['newsletter_research'] = [
                    'status' => 'research_queued',
                ];
            }
        }

        return [
            'content' => $row->body_text !== null && trim((string) $row->body_text) !== ''
                ? $row->body_text
                : (string) ($row->subject ?? ''),
            'user_id' => $account->user_id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => $meta,
            'no_chunking' => true,
        ];
    }

    /**
     * @param  array<int, array{email?: string, name?: string}>  $from
     */
    private function formatRawSender(array $from): string
    {
        $first = $from[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        $email = trim((string) ($first['email'] ?? ''));
        $name = isset($first['name']) ? trim((string) $first['name']) : '';

        if ($email === '') {
            return '';
        }

        if ($name !== '') {
            return $name.' <'.$email.'>';
        }

        return $email;
    }
}

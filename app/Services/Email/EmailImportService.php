<?php

namespace App\Services\Email;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\MailSyncRun;
use App\Services\ThoughtCaptureService;
use Throwable;

class EmailImportService
{
    public function __construct(
        private readonly EmailBodyCleanupService $bodyCleanupService,
        private readonly ParticipantNormalizer $participantNormalizer,
        private readonly EmailFilterService $filterService,
        private readonly ThoughtCaptureService $thoughtCaptureService,
    ) {}

    public function importMessage(
        MailAccount $account,
        NormalizedEmailMessage $message,
        ?MailSyncRun $syncRun = null,
    ): ImportedEmail {
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

        try {
            $cleanBody = $this->bodyCleanupService->clean($message->bodyText);
            $participants = $this->participantNormalizer->normalize($message->from, $message->to, $message->cc);
            $filter = $this->filterService->evaluate($account, $message);

            $row->fill([
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
            ]);
            $row->save();

            if ($filter['include']) {
                $capture = $this->thoughtCaptureService->create([
                    'content' => $row->body_text !== null && trim($row->body_text) !== ''
                        ? $row->body_text
                        : (string) ($row->subject ?? ''),
                    'user_id' => $account->user_id,
                    'parent_id' => null,
                    'source' => 'email',
                    'source_metadata' => [
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
                    ],
                    'no_chunking' => true,
                ]);

                $thought = $capture['thought'] ?? $capture['root'] ?? null;
                if ($thought === null) {
                    throw new \RuntimeException('Thought capture did not return a thought.');
                }

                $row->thought_id = $thought->id;
                $row->processing_status = 'imported';
                $row->save();
            }

            return $row->fresh();
        } catch (Throwable $throwable) {
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
}

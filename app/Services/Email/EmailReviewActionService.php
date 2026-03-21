<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\InboxItemAction;
use App\Models\Thought;
use App\Models\User;
use App\Services\ThoughtCaptureService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailReviewActionService
{
    public function __construct(
        private readonly EmailSenderRuleService $senderRuleService,
        private readonly ThoughtCaptureService $thoughtCaptureService,
    ) {}

    /**
     * Persist a sender rule for future mail and complete the review inbox item without retro-processing the stored email.
     *
     * @param  'allow'|'ignore'|'extra_process'  $action
     */
    public function applySenderClassification(InboxItem $item, User $user, string $action): bool
    {
        return DB::transaction(function () use ($item, $user, $action): bool {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->generator_type !== 'email_sender_review') {
                throw new InvalidArgumentException('Inbox item is not an email sender review item.');
            }

            if (! $this->isActionable($locked)) {
                return false;
            }

            if ($locked->actions()->where('action_type', 'email_sender_classify')->exists()) {
                return false;
            }

            $stored = $this->loadValidatedStoredEmailRecord($locked, $user);
            $type = $stored instanceof ImportedEmail ? 'imported_email' : 'captured_inbound_email';
            $storedId = $stored->id;

            $data = $locked->source_data ?? [];
            $senderEmail = isset($data['sender_email']) ? trim((string) $data['sender_email']) : '';
            $normalizedSender = $this->senderRuleService->normalizeSender($senderEmail);

            EmailSenderRule::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'sender_email' => $normalizedSender,
                ],
                [
                    'action' => $action,
                ],
            );

            $actedAt = now('UTC');
            $this->touchStoredEmailMetadata($type, $storedId, $user, $action, $actedAt);

            $locked->update([
                'status' => 'done',
                'snoozed_until' => null,
                'actioned_at' => $actedAt,
            ]);

            InboxItemAction::query()->create([
                'inbox_item_id' => $locked->id,
                'action_type' => 'email_sender_classify',
                'metadata' => ['sender_action' => $action],
                'created_at' => $actedAt,
            ]);

            return true;
        });
    }

    /**
     * Create an email-backed thought from the stored email record for this review item, link it, and complete the inbox.
     */
    public function saveReviewedEmailAsThought(InboxItem $item, User $user): string
    {
        $reservation = DB::transaction(function () use ($item, $user): array {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $stored = $this->loadValidatedStoredEmailRecord($locked, $user);

            $existing = $locked->actions()->where('action_type', 'save_as_thought')->latest('id')->first();
            if ($existing !== null) {
                $existingThoughtId = $this->extractThoughtId($existing);
                if ($existingThoughtId !== null) {
                    return ['thought_id' => $existingThoughtId];
                }

                $recoveredThoughtId = $this->recoverEmailThoughtIdForPendingSave($locked, $stored, $existing);
                if ($recoveredThoughtId !== null) {
                    return ['thought_id' => $recoveredThoughtId];
                }

                throw new \RuntimeException('Inbox item already has an incomplete save-as-thought action.');
            }

            if ($stored instanceof ImportedEmail && $stored->thought_id !== null) {
                return $this->finalizeInboxWithExistingThought($locked, (string) $stored->thought_id);
            }

            if ($stored instanceof CapturedInboundEmail && $stored->thought_id !== null) {
                return $this->finalizeInboxWithExistingThought($locked, (string) $stored->thought_id);
            }

            $pendingAction = InboxItemAction::query()->create([
                'inbox_item_id' => $locked->id,
                'action_type' => 'save_as_thought',
                'metadata' => ['status' => 'pending'],
                'created_at' => now('UTC'),
            ]);

            return [
                'reservation_id' => $pendingAction->id,
                'capture_options' => $stored instanceof ImportedEmail
                    ? $this->importedEmailReviewCaptureOptions($stored, $user)
                    : $this->capturedInboundEmailReviewCaptureOptions($stored, $user),
            ];
        });

        if (isset($reservation['thought_id'])) {
            return $reservation['thought_id'];
        }

        try {
            $result = $this->thoughtCaptureService->create($reservation['capture_options']);
        } catch (\Throwable $e) {
            $this->cleanupPendingSaveAsThoughtAction($item, $reservation['reservation_id']);

            throw $e;
        }

        $thought = $result['thought'] ?? $result['root'] ?? null;
        if ($thought === null) {
            $this->cleanupPendingSaveAsThoughtAction($item, $reservation['reservation_id']);

            throw new \RuntimeException('Thought capture did not return a thought.');
        }

        return DB::transaction(function () use ($item, $reservation, $thought, $user): string {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $stored = $this->loadValidatedStoredEmailRecord($locked, $user);

            $action = $locked->actions()
                ->whereKey($reservation['reservation_id'])
                ->where('action_type', 'save_as_thought')
                ->firstOrFail();

            $existingThoughtId = $this->extractThoughtId($action);
            if ($existingThoughtId !== null) {
                return $existingThoughtId;
            }

            $actedAt = now('UTC');

            $action->update([
                'metadata' => ['thought_id' => $thought->id],
            ]);

            $locked->update([
                'status' => 'done',
                'snoozed_until' => null,
                'actioned_at' => $actedAt,
            ]);

            if ($stored instanceof ImportedEmail) {
                $stored->update([
                    'thought_id' => $thought->id,
                    'processing_status' => 'imported',
                ]);
            } else {
                $stored->update([
                    'thought_id' => $thought->id,
                    'processing_status' => 'imported',
                ]);
            }

            return $thought->id;
        });
    }

    private function loadValidatedStoredEmailRecord(InboxItem $locked, User $user): ImportedEmail|CapturedInboundEmail
    {
        if ($locked->generator_type !== 'email_sender_review') {
            throw new InvalidArgumentException('Inbox item is not an email sender review item.');
        }

        $data = $locked->source_data ?? [];
        $type = $data['stored_email_type'] ?? null;
        $storedId = (int) ($data['stored_email_id'] ?? 0);
        $senderEmail = isset($data['sender_email']) ? trim((string) $data['sender_email']) : '';

        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException('Review item source_data is missing stored email type.');
        }

        if ($storedId < 1) {
            throw new InvalidArgumentException('Review item source_data is missing stored email id.');
        }

        if ($senderEmail === '') {
            throw new InvalidArgumentException('Review item source_data is missing sender email.');
        }

        $normalizedSender = $this->senderRuleService->normalizeSender($senderEmail);
        if ($normalizedSender === '') {
            throw new InvalidArgumentException('Review item source_data sender email is invalid.');
        }

        $storedNormalizedSender = $this->storedEmailNormalizedSender($type, $storedId, $user);
        if ($storedNormalizedSender === '' || $storedNormalizedSender !== $normalizedSender) {
            throw new InvalidArgumentException('Review item sender does not match stored email sender.');
        }

        return match ($type) {
            'imported_email' => ImportedEmail::query()->findOrFail($storedId),
            'captured_inbound_email' => CapturedInboundEmail::query()->findOrFail($storedId),
            default => throw new InvalidArgumentException('Unknown stored email type.'),
        };
    }

    /**
     * @return array{thought_id: string}
     */
    private function finalizeInboxWithExistingThought(InboxItem $locked, string $thoughtId): array
    {
        $actedAt = now('UTC');
        InboxItemAction::query()->create([
            'inbox_item_id' => $locked->id,
            'action_type' => 'save_as_thought',
            'metadata' => ['thought_id' => $thoughtId],
            'created_at' => $actedAt,
        ]);
        $locked->update([
            'status' => 'done',
            'snoozed_until' => null,
            'actioned_at' => $actedAt,
        ]);

        return ['thought_id' => $thoughtId];
    }

    private function recoverEmailThoughtIdForPendingSave(
        InboxItem $locked,
        ImportedEmail|CapturedInboundEmail $stored,
        InboxItemAction $action,
    ): ?string {
        if ($stored instanceof ImportedEmail && $stored->thought_id !== null) {
            return $this->finalizeSaveAsThoughtRecovery($locked, $stored, $action, (string) $stored->thought_id);
        }

        if ($stored instanceof CapturedInboundEmail && $stored->thought_id !== null) {
            return $this->finalizeSaveAsThoughtRecovery($locked, $stored, $action, (string) $stored->thought_id);
        }

        $query = Thought::query()
            ->where('user_id', $locked->user_id)
            ->where('source', 'email');

        if ($stored instanceof ImportedEmail) {
            $query->where('source_metadata->imported_email_id', $stored->id);
        } else {
            $query->where('source_metadata->captured_inbound_email_id', $stored->id);
        }

        $thought = $query->latest('created_at')->first();
        if ($thought === null) {
            return null;
        }

        return $this->finalizeSaveAsThoughtRecovery($locked, $stored, $action, $thought->id);
    }

    private function finalizeSaveAsThoughtRecovery(
        InboxItem $locked,
        ImportedEmail|CapturedInboundEmail $stored,
        InboxItemAction $action,
        string $thoughtId,
    ): string {
        $actedAt = now('UTC');

        $action->update([
            'metadata' => ['thought_id' => $thoughtId],
        ]);

        $locked->update([
            'status' => 'done',
            'snoozed_until' => null,
            'actioned_at' => $actedAt,
        ]);

        if ($stored instanceof ImportedEmail) {
            $stored->refresh();
            if ($stored->thought_id === null) {
                $stored->update([
                    'thought_id' => $thoughtId,
                    'processing_status' => 'imported',
                ]);
            }
        } else {
            $stored->refresh();
            if ($stored->thought_id === null) {
                $stored->update([
                    'thought_id' => $thoughtId,
                    'processing_status' => 'imported',
                ]);
            }
        }

        return $thoughtId;
    }

    /**
     * @return array<string, mixed>
     */
    private function importedEmailReviewCaptureOptions(ImportedEmail $row, User $user): array
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

        if ($row->rule_action !== null && trim((string) $row->rule_action) !== '') {
            $meta['sender_rule_action'] = $row->rule_action;
        }

        return [
            'content' => $row->body_text !== null && trim((string) $row->body_text) !== ''
                ? trim((string) $row->body_text)
                : (string) ($row->subject ?? ''),
            'user_id' => (int) $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => $meta,
            'no_chunking' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedInboundEmailReviewCaptureOptions(CapturedInboundEmail $row, User $user): array
    {
        $meta = [
            'message_id' => $row->message_id,
            'from' => $row->sender_email,
            'subject' => $row->subject,
            'date' => $row->received_at?->toIso8601String(),
            'attachment_names' => [],
            'captured_inbound_email_id' => $row->id,
            'sender_rule_action' => $row->rule_action,
        ];

        return [
            'content' => $row->body_text !== null && trim((string) $row->body_text) !== ''
                ? trim((string) $row->body_text)
                : (string) ($row->subject ?? ''),
            'user_id' => (int) $user->id,
            'parent_id' => null,
            'source' => 'email',
            'source_metadata' => $meta,
            'no_chunking' => true,
        ];
    }

    private function extractThoughtId(InboxItemAction $action): ?string
    {
        $thoughtId = $action->metadata['thought_id'] ?? null;

        return is_string($thoughtId) && $thoughtId !== '' ? $thoughtId : null;
    }

    private function cleanupPendingSaveAsThoughtAction(InboxItem $item, int $reservationId): void
    {
        DB::transaction(function () use ($item, $reservationId): void {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $action = $locked->actions()
                ->whereKey($reservationId)
                ->where('action_type', 'save_as_thought')
                ->first();

            if ($action !== null && $this->extractThoughtId($action) === null) {
                $action->delete();
            }
        });
    }

    private function storedEmailNormalizedSender(string $type, int $storedId, User $user): string
    {
        if ($type === 'imported_email') {
            $row = ImportedEmail::query()->find($storedId);
            if ($row === null || $row->user_id !== $user->id) {
                throw new InvalidArgumentException('Imported email not found for user.');
            }

            $rawSender = $row->rule_email;
            if (! is_string($rawSender) || trim($rawSender) === '') {
                $rawSender = $this->formatImportedEmailSender($row);
            }

            return $this->senderRuleService->normalizeSender($rawSender);
        }

        if ($type === 'captured_inbound_email') {
            $row = CapturedInboundEmail::query()->find($storedId);
            if ($row === null || $row->user_id !== $user->id) {
                throw new InvalidArgumentException('Captured inbound email not found for user.');
            }

            $rawSender = is_string($row->rule_email) && trim($row->rule_email) !== ''
                ? $row->rule_email
                : (string) $row->sender_email;

            return $this->senderRuleService->normalizeSender($rawSender);
        }

        throw new InvalidArgumentException('Unknown stored email type.');
    }

    private function isActionable(InboxItem $item): bool
    {
        if ($item->status !== 'pending') {
            return false;
        }

        if ($item->snoozed_until !== null && $item->snoozed_until->isFuture()) {
            return false;
        }

        return true;
    }

    private function touchStoredEmailMetadata(string $type, int $storedId, User $user, string $action, CarbonInterface $actedAt): void
    {
        if ($type === 'imported_email') {
            $row = ImportedEmail::query()->whereKey($storedId)->where('user_id', $user->id)->first();
            if ($row === null) {
                return;
            }

            $meta = $row->processing_metadata_json ?? [];
            $meta['email_review_triage'] = [
                'chosen_sender_action' => $action,
                'classified_at' => $actedAt->toIso8601String(),
            ];
            $row->update(['processing_metadata_json' => $meta]);

            return;
        }

        if ($type === 'captured_inbound_email') {
            $row = CapturedInboundEmail::query()->whereKey($storedId)->where('user_id', $user->id)->first();
            if ($row === null) {
                return;
            }

            $meta = $row->processing_metadata_json ?? [];
            $meta['email_review_triage'] = [
                'chosen_sender_action' => $action,
                'classified_at' => $actedAt->toIso8601String(),
            ];
            $row->update(['processing_metadata_json' => $meta]);
        }
    }

    private function formatImportedEmailSender(ImportedEmail $row): string
    {
        $first = $row->from_json[0] ?? null;
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
}

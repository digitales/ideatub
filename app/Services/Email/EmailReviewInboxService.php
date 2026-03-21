<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class EmailReviewInboxService
{
    /**
     * Create or reuse an Inbox row for a Fastmail {@see ImportedEmail} pending sender review.
     */
    public function ensureForImportedEmail(User $user, ImportedEmail $importedEmail, string $ruleAction): InboxItem
    {
        $dedupeKey = 'email_sender_review:imported_email:'.$importedEmail->id;

        $existing = InboxItem::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $sourceData = [
            'stored_email_type' => 'imported_email',
            'stored_email_id' => $importedEmail->id,
            'sender_email' => (string) ($importedEmail->rule_email ?? ''),
            'rule_action' => $ruleAction,
        ];

        try {
            return InboxItem::create([
                'user_id' => $user->id,
                'generator_type' => 'email_sender_review',
                'title' => $this->title($importedEmail),
                'body' => $this->body($importedEmail),
                'status' => 'pending',
                'snoozed_until' => null,
                'generated_at' => now(),
                'actioned_at' => null,
                'dedupe_key' => $dedupeKey,
                'source_data' => $sourceData,
            ]);
        } catch (UniqueConstraintViolationException) {
            $retry = InboxItem::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $dedupeKey)
                ->first();
            if ($retry !== null) {
                return $retry;
            }

            throw new \RuntimeException('Failed to create or load email review inbox item.');
        }
    }

    /**
     * Create or reuse an Inbox row for a Postmark {@see CapturedInboundEmail} pending sender review.
     */
    public function ensureForCapturedInboundEmail(User $user, CapturedInboundEmail $capturedInboundEmail, string $ruleAction): InboxItem
    {
        $dedupeKey = 'email_sender_review:captured_inbound_email:'.$capturedInboundEmail->id;

        $existing = InboxItem::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $senderEmail = (string) ($capturedInboundEmail->rule_email ?? $capturedInboundEmail->sender_email ?? '');

        $sourceData = [
            'stored_email_type' => 'captured_inbound_email',
            'stored_email_id' => $capturedInboundEmail->id,
            'sender_email' => $senderEmail,
            'rule_action' => $ruleAction,
        ];

        try {
            return InboxItem::create([
                'user_id' => $user->id,
                'generator_type' => 'email_sender_review',
                'title' => $this->titleForCaptured($capturedInboundEmail),
                'body' => $this->bodyForCaptured($capturedInboundEmail),
                'status' => 'pending',
                'snoozed_until' => null,
                'generated_at' => now(),
                'actioned_at' => null,
                'dedupe_key' => $dedupeKey,
                'source_data' => $sourceData,
            ]);
        } catch (UniqueConstraintViolationException) {
            $retry = InboxItem::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $dedupeKey)
                ->first();
            if ($retry !== null) {
                return $retry;
            }

            throw new \RuntimeException('Failed to create or load email review inbox item.');
        }
    }

    private function title(ImportedEmail $importedEmail): string
    {
        $sender = $importedEmail->rule_email ?? 'unknown';

        return 'Review sender: '.$sender;
    }

    private function body(ImportedEmail $importedEmail): string
    {
        $subject = $importedEmail->subject ?? '(no subject)';

        return 'This message needs sender review: '.$subject;
    }

    private function titleForCaptured(CapturedInboundEmail $capturedInboundEmail): string
    {
        $sender = $capturedInboundEmail->rule_email ?? $capturedInboundEmail->sender_email ?? 'unknown';

        return 'Review sender: '.$sender;
    }

    private function bodyForCaptured(CapturedInboundEmail $capturedInboundEmail): string
    {
        $subject = $capturedInboundEmail->subject ?? '(no subject)';

        return 'This message needs sender review: '.$subject;
    }
}

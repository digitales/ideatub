<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored Fastmail sync email row.
 *
 * `processing_status` includes legacy pipeline values (`pending`, `filtered`, `imported`)
 * and sender-policy / research lifecycle values aligned with `CapturedInboundEmail`, such as
 * `review_queued`, `research_queued`, `research_completed`, `research_partial`,
 * `research_skipped`, and `research_failed`.
 */
class ImportedEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mail_account_id',
        'mail_sync_run_id',
        'provider',
        'provider_message_id',
        'provider_thread_id',
        'provider_mailbox_id',
        'provider_mailbox_name',
        'direction',
        'subject',
        'from_json',
        'to_json',
        'cc_json',
        'participants_json',
        'sent_at',
        'received_at',
        'body_text',
        'summary',
        'message_metadata_json',
        'content_fingerprint',
        'thought_id',
        'thought_deleted_at',
        'processing_status',
        'rule_action',
        'rule_email',
        'review_inbox_item_id',
        'research_thought_id',
        'processing_metadata_json',
        'failure_count',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'from_json' => 'array',
            'to_json' => 'array',
            'cc_json' => 'array',
            'participants_json' => 'array',
            'message_metadata_json' => 'array',
            'processing_metadata_json' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'thought_deleted_at' => 'datetime',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MailSyncRun::class, 'mail_sync_run_id');
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }

    public function reviewInboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'review_inbox_item_id');
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }
}

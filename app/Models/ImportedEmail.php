<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

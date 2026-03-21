<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapturedInboundEmail extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'message_id',
        'sender_email',
        'subject',
        'body_text',
        'received_at',
        'rule_action',
        'rule_email',
        'thought_id',
        'research_thought_id',
        'review_inbox_item_id',
        'processing_status',
        'processing_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processing_metadata_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'thought_id');
    }
}

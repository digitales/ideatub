<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnmatchedInboundEmail extends Model
{
    protected $fillable = [
        'message_id',
        'from_email',
        'to_email',
        'subject',
        'body_text',
        'received_at',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }
}

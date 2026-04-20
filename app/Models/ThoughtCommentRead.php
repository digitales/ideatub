<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThoughtCommentRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'thought_id', 'last_read_at'];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public static function markRead(int $userId, string $thoughtId): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'thought_id' => $thoughtId],
            ['last_read_at' => now()],
        );
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThoughtCommentRead extends Model
{
    public $timestamps = false;

    // Composite primary key (user_id, thought_id) — no auto-increment "id" column.
    // Without this, PostgreSQL inserts emit `returning "id"` and fail (42703).
    public $incrementing = false;

    protected $primaryKey = null;

    public $keyType = 'string';

    protected $fillable = ['user_id', 'thought_id', 'last_read_at'];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public static function markRead(int $userId, string $thoughtId): void
    {
        static::query()->upsert(
            [[
                'user_id' => $userId,
                'thought_id' => $thoughtId,
                'last_read_at' => now(),
            ]],
            ['user_id', 'thought_id'],
            ['last_read_at'],
        );
    }
}

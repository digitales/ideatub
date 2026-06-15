<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitmentItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'title',
        'body',
        'project_id',
        'scope_type',
        'scope_key',
        'source_thought_id',
        'source_version_id',
        'external_key',
        'external_url',
        'owner_label',
        'due_at',
        'snoozed_until',
        'dedupe_key',
        'source_data',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'due_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'source_thought_id');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(WorkingMemoryVersion::class, 'source_version_id');
    }

    public function scopeForUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open')
            ->where(function ($inner): void {
                $inner->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
            });
    }
}

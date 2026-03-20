<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboxItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'generator_type',
        'title',
        'body',
        'status',
        'snoozed_until',
        'generated_at',
        'actioned_at',
        'dedupe_key',
        'source_data',
    ];

    protected function casts(): array
    {
        return [
            'snoozed_until' => 'datetime',
            'generated_at' => 'datetime',
            'actioned_at' => 'datetime',
            'source_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(InboxItemAction::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where(function (Builder $q): void {
                $q->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now('UTC'));
            });
    }
}

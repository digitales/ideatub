<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThoughtSuggestedLink extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'from_thought_id',
        'to_thought_id',
        'distance',
        'dismissed_at',
        'promoted_at',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'distance' => 'float',
            'dismissed_at' => 'datetime',
            'promoted_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')->whereNull('promoted_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'from_thought_id');
    }

    public function toThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'to_thought_id');
    }
}

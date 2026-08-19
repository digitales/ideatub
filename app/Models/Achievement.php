<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['user_id', 'tag', 'bullet_text', 'times_used', 'last_used_at', 'retired_at'];

    protected function casts(): array
    {
        return [
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->where('tag', $tag);
    }

    public function markUsed(): void
    {
        $this->increment('times_used');
        $this->update(['last_used_at' => now()]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkingMemory extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_key',
        'latest_version_id',
        'freshness_state',
        'last_refreshed_at',
        'build_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_refreshed_at' => 'datetime',
            'build_started_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(WorkingMemoryVersion::class, 'latest_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkingMemoryVersion::class);
    }
}

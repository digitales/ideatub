<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingMemoryInput extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'working_memory_version_id',
        'thought_id',
        'contribution_type',
        'weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
        ];
    }

    public function workingMemoryVersion(): BelongsTo
    {
        return $this->belongsTo(WorkingMemoryVersion::class);
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkingMemoryVersion extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'working_memory_id',
        'build_type',
        'summary_markdown',
        'key_concepts_json',
        'active_threads_json',
        'open_questions_json',
        'next_actions_json',
        'confidence_score',
        'source_window_start',
        'source_window_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_concepts_json' => 'array',
            'active_threads_json' => 'array',
            'open_questions_json' => 'array',
            'next_actions_json' => 'array',
            'confidence_score' => 'decimal:2',
            'source_window_start' => 'datetime',
            'source_window_end' => 'datetime',
        ];
    }

    public function workingMemory(): BelongsTo
    {
        return $this->belongsTo(WorkingMemory::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(\App\Models\WorkingMemoryInput::class);
    }
}

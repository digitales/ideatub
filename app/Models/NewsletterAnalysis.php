<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterAnalysis extends Model
{
    protected $fillable = [
        'research_thought_id',
        'source_thought_id',
        'stored_email_type',
        'stored_email_id',
        'status',
        'summary',
        'key_points',
        'positives_mentioned',
        'negatives_mentioned',
        'highlights',
        'quality_notes',
        'failure_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'positives_mentioned' => 'array',
            'negatives_mentioned' => 'array',
            'highlights' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }

    public function sourceThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'source_thought_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThoughtLinkSummary extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'source_thought_id',
        'parent_research_thought_id',
        'source_type',
        'stored_email_type',
        'stored_email_id',
        'original_url',
        'normalized_url',
        'normalized_url_hash',
        'newsletter_section_label',
        'newsletter_section_order',
        'source_excerpt',
        'classification',
        'processing_status',
        'fetch_status_code',
        'resolved_title',
        'summary_text',
        'support_judgment',
        'why_it_matters',
        'quality_notes',
        'usefulness_score',
        'section_rank',
        'failure_stage',
        'failure_reason',
        'content_fingerprint',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'newsletter_section_order' => 'integer',
            'fetch_status_code' => 'integer',
            'usefulness_score' => 'integer',
            'section_rank' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'source_thought_id');
    }

    public function parentResearchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'parent_research_thought_id');
    }
}

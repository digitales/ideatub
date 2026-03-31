<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchRun extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'idea_thought_id',
        'research_skill_id',
        'research_skill_version_id',
        'source',
        'status',
        'workflow_type_snapshot',
        'context_options_snapshot',
        'output_shape_snapshot',
        'intensity_snapshot',
        'current_stage',
        'total_stages',
        'usage_metadata',
        'final_research_thought_id',
        'error_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_options_snapshot' => 'array',
            'output_shape_snapshot' => 'array',
            'usage_metadata' => 'array',
            'current_stage' => 'integer',
            'total_stages' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ideaThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'idea_thought_id');
    }

    public function researchSkill(): BelongsTo
    {
        return $this->belongsTo(ResearchSkill::class);
    }

    public function researchSkillVersion(): BelongsTo
    {
        return $this->belongsTo(ResearchSkillVersion::class);
    }

    public function finalResearchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'final_research_thought_id');
    }
}

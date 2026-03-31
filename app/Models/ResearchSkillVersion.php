<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchSkillVersion extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'research_skill_id',
        'version',
        'workflow_type',
        'instructions',
        'context_options',
        'output_shape',
        'intensity',
        'is_auto_run_eligible',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'context_options' => 'array',
            'output_shape' => 'array',
            'is_auto_run_eligible' => 'boolean',
        ];
    }

    public function researchSkill(): BelongsTo
    {
        return $this->belongsTo(ResearchSkill::class);
    }

    /**
     * @return HasMany<ResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class);
    }
}

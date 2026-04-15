<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingSkillVersion extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'meeting_skill_id',
        'version',
        'workflow_type',
        'instructions',
        'context_options',
        'output_shape',
        'core_categories',
        'custom_categories',
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
            'core_categories' => 'array',
            'custom_categories' => 'array',
            'is_auto_run_eligible' => 'boolean',
        ];
    }

    public function meetingSkill(): BelongsTo
    {
        return $this->belongsTo(MeetingSkill::class);
    }

    /**
     * @return HasMany<MeetingRun, $this>
     */
    public function meetingRuns(): HasMany
    {
        return $this->hasMany(MeetingRun::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRun extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'meeting_thought_id',
        'meeting_skill_id',
        'meeting_skill_version_id',
        'source',
        'status',
        'workflow_type_snapshot',
        'context_options_snapshot',
        'output_shape_snapshot',
        'core_categories_snapshot',
        'custom_categories_snapshot',
        'intensity_snapshot',
        'current_stage',
        'total_stages',
        'usage_metadata',
        'final_meeting_thought_id',
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
            'core_categories_snapshot' => 'array',
            'custom_categories_snapshot' => 'array',
            'usage_metadata' => 'array',
            'current_stage' => 'integer',
            'total_stages' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meetingThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'meeting_thought_id');
    }

    public function meetingSkill(): BelongsTo
    {
        return $this->belongsTo(MeetingSkill::class);
    }

    public function meetingSkillVersion(): BelongsTo
    {
        return $this->belongsTo(MeetingSkillVersion::class);
    }

    public function skillVersion(): BelongsTo
    {
        return $this->meetingSkillVersion();
    }

    public function finalMeetingThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'final_meeting_thought_id');
    }
}

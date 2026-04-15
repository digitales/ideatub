<?php

namespace Database\Factories;

use App\Models\MeetingRun;
use App\Models\MeetingSkill;
use App\Models\MeetingSkillVersion;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingRun>
 */
class MeetingRunFactory extends Factory
{
    protected $model = MeetingRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'meeting_thought_id' => Thought::factory(),
            'meeting_skill_id' => MeetingSkill::factory(),
            'meeting_skill_version_id' => fn (array $attributes) => MeetingSkillVersion::factory()->create([
                'meeting_skill_id' => $attributes['meeting_skill_id'],
            ])->id,
            'source' => 'web',
            'status' => 'queued',
            'workflow_type_snapshot' => 'meeting_brief',
            'context_options_snapshot' => null,
            'output_shape_snapshot' => null,
            'core_categories_snapshot' => ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'],
            'custom_categories_snapshot' => [],
            'intensity_snapshot' => 'standard',
            'current_stage' => 0,
            'total_stages' => 1,
            'usage_metadata' => null,
            'final_meeting_thought_id' => null,
            'error_summary' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (MeetingRun $run): void {
            Thought::withoutEvents(function () use ($run): void {
                Thought::query()->whereKey($run->meeting_thought_id)->update(['user_id' => $run->user_id]);
            });

            MeetingSkill::query()->whereKey($run->meeting_skill_id)->update(['user_id' => $run->user_id]);
        });
    }
}

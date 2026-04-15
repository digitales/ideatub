<?php

namespace Database\Factories;

use App\Models\MeetingSkill;
use App\Models\MeetingSkillVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingSkillVersion>
 */
class MeetingSkillVersionFactory extends Factory
{
    protected $model = MeetingSkillVersion::class;

    public function definition(): array
    {
        return [
            'meeting_skill_id' => MeetingSkill::factory(),
            'version' => null,
            'workflow_type' => 'meeting_brief',
            'instructions' => '',
            'context_options' => null,
            'output_shape' => null,
            'core_categories' => ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'],
            'custom_categories' => [],
            'intensity' => 'standard',
            'is_auto_run_eligible' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (MeetingSkillVersion $version): void {
            if ($version->version !== null) {
                return;
            }

            $skillId = $version->meeting_skill_id;

            if ($skillId === null && $version->relationLoaded('meetingSkill')) {
                $skillId = $version->meetingSkill?->getKey();
            }

            $version->version = $this->nextVersionForSkill($skillId);
        });
    }

    private function nextVersionForSkill(?int $skillId): int
    {
        if ($skillId === null) {
            return 1;
        }

        return (int) MeetingSkillVersion::query()
            ->where('meeting_skill_id', $skillId)
            ->max('version') + 1;
    }
}

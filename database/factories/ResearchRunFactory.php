<?php

namespace Database\Factories;

use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchRun>
 */
class ResearchRunFactory extends Factory
{
    protected $model = ResearchRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idea_thought_id' => Thought::factory(),
            'research_skill_id' => ResearchSkill::factory(),
            'research_skill_version_id' => fn (array $attributes) => ResearchSkillVersion::factory()->create([
                'research_skill_id' => $attributes['research_skill_id'],
            ])->id,
            'source' => 'web',
            'status' => 'queued',
            'workflow_type_snapshot' => 'quick_brief',
            'context_options_snapshot' => null,
            'output_shape_snapshot' => null,
            'intensity_snapshot' => 'standard',
            'current_stage' => 0,
            'total_stages' => 1,
            'usage_metadata' => null,
            'final_research_thought_id' => null,
            'error_summary' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ResearchRun $run): void {
            Thought::withoutEvents(function () use ($run): void {
                Thought::query()->whereKey($run->idea_thought_id)->update(['user_id' => $run->user_id]);
            });

            ResearchSkill::query()->whereKey($run->research_skill_id)->update(['user_id' => $run->user_id]);
        });
    }
}

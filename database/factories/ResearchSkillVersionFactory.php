<?php

namespace Database\Factories;

use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchSkillVersion>
 */
class ResearchSkillVersionFactory extends Factory
{
    protected $model = ResearchSkillVersion::class;

    public function definition(): array
    {
        return [
            'research_skill_id' => ResearchSkill::factory(),
            'version' => 1,
            'workflow_type' => 'sequential',
            'instructions' => fake()->paragraph(),
            'context_options' => [],
            'output_shape' => null,
            'intensity' => 'standard',
            'is_auto_run_eligible' => false,
        ];
    }
}

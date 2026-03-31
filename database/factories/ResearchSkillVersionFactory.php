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
            'version' => null,
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'context_options' => null,
            'output_shape' => null,
            'intensity' => 'standard',
            'is_auto_run_eligible' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ResearchSkillVersion $version): void {
            if ($version->version !== null) {
                return;
            }

            $skillId = $version->research_skill_id;

            if ($skillId === null && $version->relationLoaded('researchSkill')) {
                $skillId = $version->researchSkill?->getKey();
            }

            $version->version = $this->nextVersionForSkill($skillId);
        });
    }

    private function nextVersionForSkill(?int $skillId): int
    {
        if ($skillId === null) {
            return 1;
        }

        return (int) ResearchSkillVersion::query()
            ->where('research_skill_id', $skillId)
            ->max('version') + 1;
    }
}

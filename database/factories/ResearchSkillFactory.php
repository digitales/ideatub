<?php

namespace Database\Factories;

use App\Models\ResearchSkill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchSkill>
 */
class ResearchSkillFactory extends Factory
{
    protected $model = ResearchSkill::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => '',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => false,
            'is_active' => true,
            'latest_version_number' => 0,
        ];
    }
}

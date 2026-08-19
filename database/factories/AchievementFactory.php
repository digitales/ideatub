<?php

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Achievement> */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tag' => fake()->word(),
            'bullet_text' => fake()->sentence(),
            'times_used' => 0,
        ];
    }
}

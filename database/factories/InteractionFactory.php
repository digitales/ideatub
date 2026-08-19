<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Interaction> */
class InteractionFactory extends Factory
{
    protected $model = Interaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'application_id' => Application::factory(),
            'type' => fake()->randomElement(Interaction::TYPES),
            'occurred_at' => now(),
            'summary' => fake()->sentence(),
        ];
    }
}

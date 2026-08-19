<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'role_title' => fake()->jobTitle(),
            'stage' => 'researching',
            'last_activity_at' => now(),
        ];
    }
}

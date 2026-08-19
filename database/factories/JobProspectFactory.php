<?php

namespace Database\Factories;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobProspect> */
class JobProspectFactory extends Factory
{
    protected $model = JobProspect::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company' => fake()->company(),
            'role_title' => fake()->jobTitle(),
            'source' => fake()->randomElement(JobProspect::SOURCES),
            'url' => fake()->optional()->url(),
            'status' => 'new',
            'discovered_at' => now(),
        ];
    }
}

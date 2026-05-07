<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkingMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingMemory>
 */
class WorkingMemoryFactory extends Factory
{
    protected $model = WorkingMemory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scope_type' => 'project',
            'scope_key' => fake()->unique()->slug(2),
            'freshness_state' => 'stale',
        ];
    }
}

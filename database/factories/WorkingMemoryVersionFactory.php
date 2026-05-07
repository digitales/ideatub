<?php

namespace Database\Factories;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingMemoryVersion>
 */
class WorkingMemoryVersionFactory extends Factory
{
    protected $model = WorkingMemoryVersion::class;

    public function definition(): array
    {
        return [
            'working_memory_id' => WorkingMemory::factory(),
            'build_type' => 'consolidated',
            'summary_markdown' => '# stub',
            'confidence_score' => 0,
            'authoring_status' => 'disabled',
        ];
    }
}

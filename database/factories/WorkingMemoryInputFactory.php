<?php

namespace Database\Factories;

use App\Models\Thought;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingMemoryInput>
 */
class WorkingMemoryInputFactory extends Factory
{
    protected $model = WorkingMemoryInput::class;

    public function definition(): array
    {
        return [
            'working_memory_version_id' => WorkingMemoryVersion::factory(),
            'thought_id' => Thought::factory(),
            'source_version_id' => null,
            'contribution_type' => 'primary',
            'weight' => 1.0,
        ];
    }

    public function compactionSource(): static
    {
        return $this->state(fn () => [
            'thought_id' => null,
            'source_version_id' => WorkingMemoryVersion::factory()->state([
                'build_type' => 'compaction:meeting',
            ]),
            'contribution_type' => 'compaction',
        ]);
    }
}

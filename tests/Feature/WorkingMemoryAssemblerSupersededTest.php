<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAssemblerSupersededTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canonical_payload_ignores_superseded_external_versions(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $superseded = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'summary_markdown' => '## Current Focus\n- Old',
            'created_at' => now(),
        ]);

        $current = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'summary_markdown' => '## Current Focus\n- New',
            'created_at' => now()->subHour(),
        ]);

        $superseded->forceFill([
            'superseded_at' => now(),
            'superseded_by_version_id' => $current->id,
        ])->save();

        $memory->forceFill(['latest_version_id' => $current->id])->save();

        $payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'project', 'dezeen');

        $this->assertStringContainsString('New', $payload['summary_markdown']);
        $this->assertSame((string) $current->id, $payload['canonical_version_id']);
    }
}

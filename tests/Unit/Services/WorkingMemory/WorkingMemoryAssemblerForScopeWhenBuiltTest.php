<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAssemblerForScopeWhenBuiltTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function for_scope_when_built_returns_null_when_memory_missing(): void
    {
        $user = User::factory()->create();

        $payload = app(WorkingMemoryAssembler::class)->forScopeWhenBuilt(
            (int) $user->id,
            'project',
            '019e4f5c-0abd-7331-bbff-5ca7be36f8cf'
        );

        $this->assertNull($payload);
    }

    #[Test]
    public function for_scope_when_built_returns_null_when_no_authoritative_version(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'my-app',
        ]);

        $payload = app(WorkingMemoryAssembler::class)->forScopeWhenBuilt(
            (int) $user->id,
            'project',
            'my-app'
        );

        $this->assertNull($payload);
        $this->assertSame($memory->id, $memory->fresh()->id);
    }

    #[Test]
    public function for_scope_when_built_returns_payload_for_external_version(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'my-app',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'structured_sections_json' => [
                'Current Focus' => [[
                    'text' => 'Built focus.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
            ],
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $payload = app(WorkingMemoryAssembler::class)->forScopeWhenBuilt(
            (int) $user->id,
            'project',
            'MY-APP'
        );

        $this->assertIsArray($payload);
        $this->assertSame('Current Focus', array_key_first($payload['structured_sections']));
        $this->assertSame('external', $payload['baseline_build_type']);
    }
}

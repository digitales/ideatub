<?php

namespace Tests\Unit\Services\Inbox\Generators;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Inbox\Generators\WorkingMemoryFallbackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryFallbackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_memory_produces_inbox_payload_with_dedupe_key(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $payloads = app(WorkingMemoryFallbackGenerator::class)->generate($user);

        $this->assertCount(1, $payloads);
        $this->assertSame('wm_fallback', $payloads[0]['generator_type']);
        $this->assertSame('wm_fallback:'.$memory->id, $payloads[0]['dedupe_key']);
    }

    public function test_returns_empty_when_feature_disabled(): void
    {
        config(['features.attention_pulse' => false]);
        $user = User::factory()->create();

        $this->assertSame([], app(WorkingMemoryFallbackGenerator::class)->generate($user));
    }
}

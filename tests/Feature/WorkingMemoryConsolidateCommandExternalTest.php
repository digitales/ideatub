<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkingMemoryConsolidateCommandExternalTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidate_skips_scope_with_fresh_external_by_default(): void
    {
        Queue::fake();
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        Artisan::call('working-memory:consolidate', [
            '--user' => (string) $user->id,
            '--scope_type' => 'global',
            '--scope_key' => 'global',
        ]);

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
        $this->assertStringContainsString('skipped 1', Artisan::output());
    }

    public function test_consolidate_force_dispatches_despite_fresh_external(): void
    {
        Queue::fake();
        config(['working_memory.external_protect_days' => 14]);
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);
        WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);

        Artisan::call('working-memory:consolidate', [
            '--user' => (string) $user->id,
            '--scope_type' => 'global',
            '--scope_key' => 'global',
            '--force' => true,
        ]);

        Queue::assertPushed(ConsolidateWorkingMemory::class);
    }
}

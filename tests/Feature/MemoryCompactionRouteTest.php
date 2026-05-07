<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryCompactionRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['features.working_memory_ui' => true]);
    }

    #[Test]
    public function it_shows_a_compaction_to_its_owner(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'summary_markdown' => "## Summary\nWeekly check-in.",
        ]);

        $this->actingAs($user)
            ->get("/memory/project/dezeen/compactions/{$version->id}")
            ->assertOk()
            ->assertSeeText('Weekly check-in')
            ->assertSeeText('compaction:meeting');
    }

    #[Test]
    public function it_returns_404_for_other_users_compactions(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $memory = WorkingMemory::factory()->create(['user_id' => $owner->id]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
        ]);

        $this->actingAs($intruder)
            ->get("/memory/{$memory->scope_type}/{$memory->scope_key}/compactions/{$version->id}")
            ->assertNotFound();
    }

    #[Test]
    public function it_returns_404_for_non_compaction_versions(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
        ]);

        $this->actingAs($user)
            ->get("/memory/project/dezeen/compactions/{$version->id}")
            ->assertNotFound();
    }
}

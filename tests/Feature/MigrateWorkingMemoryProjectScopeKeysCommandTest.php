<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrateWorkingMemoryProjectScopeKeysCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_migrates_client_root_slug_to_project_uuid(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys')
            ->assertSuccessful();

        $memory->refresh();
        $this->assertSame((string) $root->id, $memory->scope_key);
    }

    #[Test]
    public function it_migrates_child_slug_to_project_uuid(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen/foo',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys')
            ->assertSuccessful();

        $memory->refresh();
        $this->assertSame((string) $child->id, $memory->scope_key);
    }

    #[Test]
    public function dry_run_does_not_update_scope_key(): void
    {
        $user = User::factory()->create();
        Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys', ['--dry-run' => true])
            ->assertSuccessful();

        $memory->refresh();
        $this->assertSame('dezeen', $memory->scope_key);
    }

    #[Test]
    public function it_skips_when_no_matching_project(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys')
            ->assertSuccessful();

        $memory->refresh();
        $this->assertSame('dezeen', $memory->scope_key);
    }

    #[Test]
    public function it_does_not_touch_uuid_scope_keys(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys')
            ->assertSuccessful();

        $memory->refresh();
        $this->assertSame((string) $project->id, $memory->scope_key);
    }

    #[Test]
    public function it_limits_to_a_single_user_when_option_is_set(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Project::factory()->elixirrClientRoot('dezeen')->for($userA)->create();
        Project::factory()->elixirrClientRoot('dezeen')->for($userB)->create();

        $memoryA = WorkingMemory::factory()->for($userA)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $memoryB = WorkingMemory::factory()->for($userB)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys', [
            '--user' => (string) $userA->id,
        ])->assertSuccessful();

        $memoryA->refresh();
        $memoryB->refresh();

        $this->assertTrue(str($memoryA->scope_key)->isUuid());
        $this->assertSame('dezeen', $memoryB->scope_key);
    }

    #[Test]
    public function it_skips_when_target_uuid_scope_already_exists(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

        WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $root->id,
        ]);

        $legacy = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

        $this->artisan('working-memory:migrate-project-scope-keys')
            ->assertSuccessful();

        $legacy->refresh();
        $this->assertSame('dezeen', $legacy->scope_key);
    }
}

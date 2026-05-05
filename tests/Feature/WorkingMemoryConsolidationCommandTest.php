<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class WorkingMemoryConsolidationCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_incremental_refresh_after_thought_creation(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Capture integration notes for incremental refresh behavior.',
        ]);

        Queue::assertPushed(\App\Jobs\RefreshWorkingMemoryIncremental::class);
    }

    #[Test]
    public function it_rebuilds_all_discovered_scopes_for_a_user(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source_metadata' => ['project' => 'My-App'],
        ]);
        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        $builder = Mockery::mock(WorkingMemoryBuilderService::class);
        $builder->shouldReceive('buildConsolidated')->once()->with($user->id, 'global', 'global');
        $builder->shouldReceive('buildConsolidated')->once()->with($user->id, 'project', 'my-app');
        $builder->shouldReceive('buildConsolidated')->once()->with($user->id, 'project', (string) $project->id);
        $this->app->instance(WorkingMemoryBuilderService::class, $builder);

        $this->artisan('working-memory:consolidate', ['--user' => (string) $user->id])
            ->assertSuccessful();
    }

    #[Test]
    public function it_supports_scope_options_for_targeted_consolidation(): void
    {
        $user = User::factory()->create();

        $builder = Mockery::mock(WorkingMemoryBuilderService::class);
        $builder->shouldReceive('buildConsolidated')->once()->with($user->id, 'project', 'my-app');
        $this->app->instance(WorkingMemoryBuilderService::class, $builder);

        $this->artisan('working-memory:consolidate', [
            '--user' => (string) $user->id,
            '--scope_type' => 'project',
            '--scope_key' => 'my-app',
        ])->assertSuccessful();
    }

    #[Test]
    public function it_requires_scope_type_and_scope_key_to_be_supplied_together(): void
    {
        $this->artisan('working-memory:consolidate', ['--scope_type' => 'project'])
            ->assertFailed();
    }

    #[Test]
    public function it_registers_a_daily_consolidation_schedule(): void
    {
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(function ($scheduledEvent): bool {
            $command = (string) ($scheduledEvent->command ?? '');

            return str_contains($command, 'working-memory:consolidate');
        });

        $this->assertNotNull($event);

        $reflection = new ReflectionClass($event);
        $property = $reflection->getProperty('expression');
        $property->setAccessible(true);
        $this->assertSame('45 2 * * *', (string) $property->getValue($event));
    }
}

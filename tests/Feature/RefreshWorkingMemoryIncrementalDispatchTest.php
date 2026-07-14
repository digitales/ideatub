<?php

namespace Tests\Feature;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\RefreshWorkingMemoryIncrementalScope;
use App\Jobs\WorkingMemoryRebuildJob;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\UncompactedThoughtResolver;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class RefreshWorkingMemoryIncrementalDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function orchestrator_dispatches_one_scope_job_per_affected_scope(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Tagged project note.',
            'metadata' => ['tags' => ['research', 'ai']],
            'source_metadata' => ['project' => 'my-app'],
        ]);
        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        $job = new RefreshWorkingMemoryIncremental((string) $thought->id);
        $job->handle(app(WorkingMemoryScopeResolver::class));

        Queue::assertPushed(RefreshWorkingMemoryIncrementalScope::class, 5);
        Queue::assertPushed(
            RefreshWorkingMemoryIncrementalScope::class,
            fn (RefreshWorkingMemoryIncrementalScope $scopeJob): bool => $this->matchesScopeJob(
                $scopeJob,
                $user->id,
                'global',
                'global',
                (string) $thought->id,
            ),
        );
        Queue::assertPushed(
            RefreshWorkingMemoryIncrementalScope::class,
            fn (RefreshWorkingMemoryIncrementalScope $scopeJob): bool => $this->matchesScopeJob(
                $scopeJob,
                $user->id,
                'project',
                'my-app',
                (string) $thought->id,
            ),
        );
        Queue::assertPushed(
            RefreshWorkingMemoryIncrementalScope::class,
            fn (RefreshWorkingMemoryIncrementalScope $scopeJob): bool => $this->matchesScopeJob(
                $scopeJob,
                $user->id,
                'project',
                (string) $project->id,
                (string) $thought->id,
            ),
        );
        Queue::assertPushed(
            RefreshWorkingMemoryIncrementalScope::class,
            fn (RefreshWorkingMemoryIncrementalScope $scopeJob): bool => $this->matchesScopeJob(
                $scopeJob,
                $user->id,
                'tag',
                'research',
                (string) $thought->id,
            ),
        );
        Queue::assertPushed(
            RefreshWorkingMemoryIncrementalScope::class,
            fn (RefreshWorkingMemoryIncrementalScope $scopeJob): bool => $this->matchesScopeJob(
                $scopeJob,
                $user->id,
                'tag',
                'ai',
                (string) $thought->id,
            ),
        );
    }

    #[Test]
    public function scope_job_timeout_uses_configured_seconds(): void
    {
        config(['working_memory.incremental_scope_job_timeout_seconds' => 420]);

        $job = new RefreshWorkingMemoryIncrementalScope(1, 'global', 'global');

        $this->assertSame(420, $job->timeout);
    }

    #[Test]
    public function scope_job_timeout_has_a_sensible_minimum(): void
    {
        config(['working_memory.incremental_scope_job_timeout_seconds' => 10]);

        $job = new RefreshWorkingMemoryIncrementalScope(1, 'global', 'global');

        $this->assertSame(60, $job->timeout);
    }

    #[Test]
    public function scope_job_skips_build_when_compaction_primary_has_no_delta(): void
    {
        config(['working_memory.compaction_primary' => true]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
            'build_started_at' => now(),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-05 10:00:00', 'UTC'),
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:weekly-digest',
            'created_at' => Carbon::parse('2026-05-06 10:00:00', 'UTC'),
        ]);

        WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'incremental',
            'created_at' => Carbon::parse('2026-05-06 11:00:00', 'UTC'),
        ]);

        $builder = Mockery::mock(WorkingMemoryBuilderService::class);
        $builder->shouldNotReceive('buildIncremental');
        $this->app->instance(WorkingMemoryBuilderService::class, $builder);

        $job = new RefreshWorkingMemoryIncrementalScope($user->id, 'global', 'global');
        $job->handle($builder, app(UncompactedThoughtResolver::class));

        $this->assertNull($memory->fresh()->build_started_at);
    }

    #[Test]
    public function thought_observer_skips_auto_rebuild_when_fresh_external_memory_exists(): void
    {
        Queue::fake();
        config([
            'working_memory.external_protect_days' => 14,
            'working_memory.compaction_primary' => true,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
        ]);
        $external = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subDay(),
        ]);
        $memory->update(['latest_version_id' => $external->id]);

        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        Queue::fake();

        $thought->update(['content' => 'Edited content to trigger ThoughtObserver.']);

        Queue::assertNotPushed(WorkingMemoryRebuildJob::class);
    }

    private function matchesScopeJob(
        RefreshWorkingMemoryIncrementalScope $job,
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $thoughtId,
    ): bool {
        return $this->readJobProperty($job, 'userId') === $userId
            && $this->readJobProperty($job, 'scopeType') === $scopeType
            && $this->readJobProperty($job, 'scopeKey') === $scopeKey
            && $this->readJobProperty($job, 'thoughtId') === $thoughtId;
    }

    private function readJobProperty(RefreshWorkingMemoryIncrementalScope $job, string $property): mixed
    {
        $reflection = new ReflectionClass($job);

        return $reflection->getProperty($property)->getValue($job);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\RefreshWorkingMemoryIncrementalScope;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

<?php

namespace Tests\Feature;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\WorkingMemoryRebuildJob;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\ProjectMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectThoughtWorkingMemoryRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function attaching_thought_to_project_dispatches_working_memory_incremental_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project scoped note.',
        ]);

        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        // Pivot hook plus possible Thought model touch both enqueue refreshes.
        Queue::assertPushed(RefreshWorkingMemoryIncremental::class);
    }

    #[Test]
    public function attaching_survives_when_rebuild_unique_lock_already_held_on_database_cache(): void
    {
        // Regression for production 25P02: unique job lock acquisition used to run inside
        // ProjectMembershipService's transaction. After-commit deferral (see sibling test)
        // keeps attach durable; a pre-held unique lock must not roll back membership.
        // Use the array cache here so RefreshDatabase's wrapping transaction is not poisoned
        // by database cache_locks unique conflicts (Postgres 25P02).
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $lockKey = 'laravel_unique_job:'.WorkingMemoryRebuildJob::class.':wm-auto-rebuild:'.$project->id;
        $this->assertTrue(Cache::lock($lockKey, 600)->get());

        DB::transaction(function () use ($project, $thought): void {
            app(ProjectMembershipService::class)->addThought($project, $thought);
        });

        $this->assertTrue(
            $project->thoughts()->whereKey($thought->id)->exists(),
            'Thought attach must persist even when WorkingMemoryRebuildJob unique lock is already held.',
        );
        Queue::assertNotPushed(WorkingMemoryRebuildJob::class);
    }

    #[Test]
    public function attaching_defers_rebuild_job_until_transaction_commits(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        DB::transaction(function () use ($project, $thought): void {
            app(ProjectMembershipService::class)->addThought($project, $thought);
            Queue::assertNotPushed(WorkingMemoryRebuildJob::class);
        });

        Queue::assertPushed(
            WorkingMemoryRebuildJob::class,
            fn (WorkingMemoryRebuildJob $job): bool => $job->projectId === (string) $project->id,
        );
    }

    #[Test]
    public function detaching_thought_from_project_dispatches_working_memory_incremental_refresh(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project scoped note.',
        ]);
        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        Queue::fake();

        $thought->projects()->detach($project->id);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class, 1);
    }
}

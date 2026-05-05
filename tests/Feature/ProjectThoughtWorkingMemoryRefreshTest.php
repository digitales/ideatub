<?php

namespace Tests\Feature;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function detaching_thought_from_project_dispatches_working_memory_incremental_refresh(): void
    {
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

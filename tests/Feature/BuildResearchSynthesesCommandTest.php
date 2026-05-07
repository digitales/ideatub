<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildResearchSynthesesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_research_jobs_for_scopes_at_or_above_threshold(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 2]);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $exit = $this->artisan('compactions:research')->run();

        $this->assertSame(0, $exit);
        // WorkingMemoryScopeResolver: global/global, project/dezeen (source_metadata.project),
        // tag/research + tag/project:dezeen (metadata.tags), insights/global (research thought).
        // Factory user has no WM forced tags and thoughts have no project pivots → 5 tuples × 2 thoughts, each count 2.
        Queue::assertPushed(SynthesizeResearchCompactionJob::class, 5);
        Queue::assertPushed(SynthesizeResearchCompactionJob::class, function (SynthesizeResearchCompactionJob $job) use ($user): bool {
            return $job->userId === $user->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }

    #[Test]
    public function it_skips_scopes_below_threshold(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 5]);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $this->artisan('compactions:research')->run();

        Queue::assertNotPushed(SynthesizeResearchCompactionJob::class);
    }

    #[Test]
    public function it_dispatches_distinct_jobs_per_user_in_the_same_scope(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 1]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        foreach ([$userA, $userB] as $user) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
                'source_metadata' => ['project' => 'dezeen'],
            ]);
        }

        $exit = $this->artisan('compactions:research')->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(SynthesizeResearchCompactionJob::class, function (SynthesizeResearchCompactionJob $job) use ($userA): bool {
            return $job->userId === $userA->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });

        Queue::assertPushed(SynthesizeResearchCompactionJob::class, function (SynthesizeResearchCompactionJob $job) use ($userB): bool {
            return $job->userId === $userB->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ThoughtObserverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_meeting_compaction_for_meeting_thoughts(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        Queue::assertPushed(SynthesizeMeetingCompactionJob::class);
    }

    #[Test]
    public function it_does_not_dispatch_meeting_compaction_for_non_meeting_thoughts(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        Queue::assertNotPushed(SynthesizeMeetingCompactionJob::class);
    }

    #[Test]
    public function it_delays_incremental_refresh_for_meeting_thoughts_to_avoid_compaction_race(): void
    {
        Queue::fake();
        config(['working_memory.meeting_refresh_delay_seconds' => 60]);

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class, function (RefreshWorkingMemoryIncremental $job): bool {
            // Pending job's delay is set when the observer wants the compaction to win the race.
            return $job->delay !== null;
        });
    }

    #[Test]
    public function it_does_not_delay_incremental_refresh_for_non_meeting_thoughts(): void
    {
        Queue::fake();
        config(['working_memory.meeting_refresh_delay_seconds' => 60]);

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class, function (RefreshWorkingMemoryIncremental $job): bool {
            return $job->delay === null;
        });
    }

    #[Test]
    public function it_skips_meeting_refresh_delay_when_config_is_zero(): void
    {
        Queue::fake();
        config(['working_memory.meeting_refresh_delay_seconds' => 0]);

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class, function (RefreshWorkingMemoryIncremental $job): bool {
            return $job->delay === null;
        });
    }
}

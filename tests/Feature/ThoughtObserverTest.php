<?php

namespace Tests\Feature;

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
}

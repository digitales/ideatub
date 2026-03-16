<?php

namespace Tests\Feature;

use App\Events\ThoughtCreated;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ThoughtCreatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_thought_created_dispatches_event_when_reverb_driver(): void
    {
        config(['realtime.driver' => 'reverb']);
        Event::fake([ThoughtCreated::class]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
        ]);

        Event::assertDispatched(ThoughtCreated::class, function ($e) use ($thought): bool {
            return $e->thought->id === $thought->id
                && $e->thought->user_id === $thought->user_id;
        });
    }

    public function test_thought_created_does_not_dispatch_when_polling_driver(): void
    {
        config(['realtime.driver' => 'polling']);
        Event::fake([ThoughtCreated::class]);

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
        ]);

        Event::assertNotDispatched(ThoughtCreated::class);
    }
}

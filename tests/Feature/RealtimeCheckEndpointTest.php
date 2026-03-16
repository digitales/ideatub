<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeCheckEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_check_requires_auth(): void
    {
        $response = $this->getJson(route('api.thoughts.realtime-check', ['since' => now()->toIso8601String()]));

        $response->assertStatus(401);
    }

    public function test_realtime_check_returns_has_new_false_when_no_new_thoughts_since(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->getJson(route('api.thoughts.realtime-check', [
            'since' => $thought->created_at->toIso8601String(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('has_new', false);
    }

    public function test_realtime_check_returns_has_new_true_when_new_thought_after_since(): void
    {
        $user = User::factory()->create();
        $oldThought = Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subMinutes(5),
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'New thought',
        ]);

        $response = $this->actingAs($user)->getJson(route('api.thoughts.realtime-check', [
            'since' => $oldThought->created_at->toIso8601String(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('has_new', true);
    }
}

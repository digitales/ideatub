<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedResearchPasswordRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_password_attempts_returns_429(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'password_hash' => bcrypt('correct'),
        ]);

        for ($i = 0; $i < 9; $i++) {
            $this->post('/r/' . $share->token, ['password' => 'wrong']);
        }

        // 10th attempt — still within limit
        $under = $this->post('/r/' . $share->token, ['password' => 'wrong']);
        $under->assertStatus(401);

        // 11th attempt — now throttled
        $response = $this->post('/r/' . $share->token, ['password' => 'wrong']);
        $response->assertStatus(429);
    }
}

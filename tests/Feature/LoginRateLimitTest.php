<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_login_attempts_returns_429(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'user@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }
}

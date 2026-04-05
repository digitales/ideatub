<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthRegistrationThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_oauth_register_attempts_returns_429(): void
    {
        // Note: oauth-mcp must be enabled for these routes to exist.
        // The route is only registered when config('oauth-mcp.enabled', true) is true.
        config(['oauth-mcp.enabled' => true]);
        config(['oauth-mcp.allowed_redirect_hosts' => ['example.com']]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/oauth/register', [
                'redirect_uris' => ['https://example.com/callback'],
            ]);
        }

        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(429);
    }
}

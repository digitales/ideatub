<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpLoopbackRedirectAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_register_accepts_localhost_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['http://localhost:8765/oauth/callback'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            ['http://localhost:8765/oauth/callback'],
            $response->json('redirect_uris')
        );
    }

    public function test_oauth_register_accepts_ipv4_loopback_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['http://127.0.0.1:8765/oauth/callback'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            ['http://127.0.0.1:8765/oauth/callback'],
            $response->json('redirect_uris')
        );
    }

    public function test_oauth_register_accepts_ipv6_loopback_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['http://[::1]:8765/oauth/callback'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            ['http://[::1]:8765/oauth/callback'],
            $response->json('redirect_uris')
        );
    }
}

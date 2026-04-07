<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpClaudeRedirectAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_register_accepts_claude_mcp_callback_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['client_id', 'redirect_uris']);
        $this->assertSame(
            ['https://claude.ai/api/mcp/auth_callback'],
            $response->json('redirect_uris')
        );
    }

    public function test_oauth_register_accepts_claude_com_mcp_callback_redirect_uri(): void
    {
        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://claude.com/api/mcp/auth_callback'],
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            ['https://claude.com/api/mcp/auth_callback'],
            $response->json('redirect_uris')
        );
    }
}

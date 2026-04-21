<?php

namespace Tests\Feature;

use App\Models\OauthMcpAuthorizationCode;
use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpAuthorizationCodeRefreshIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_endpoint_returns_refresh_token_and_persists_family(): void
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');
        config()->set('oauth-mcp.resource', 'https://example.test/api/mcp');

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = OauthMcpAuthorizationCode::create([
            'code' => 'test-code',
            'client_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://example.test/api/mcp',
            'scope' => 'ideatub:mcp',
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'test-code',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'client_id' => $client->id,
            'code_verifier' => $verifier,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope']);
        $this->assertSame(64, strlen($response->json('refresh_token')));

        $family = OauthMcpRefreshTokenFamily::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($client->id, $family->client_id);
        $this->assertSame('https://example.test/api/mcp', $family->resource);

        $hash = hash('sha256', $response->json('refresh_token'));
        $this->assertTrue(OauthMcpRefreshToken::where('token_hash', $hash)->exists());
    }
}

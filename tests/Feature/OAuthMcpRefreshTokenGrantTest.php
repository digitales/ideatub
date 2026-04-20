<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRefreshTokenGrantTest extends TestCase
{
    use RefreshDatabase;

    private function setupTokens(): array
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');
        config()->set('oauth-mcp.resource', 'https://example.test/api/mcp');

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $issue = app(OAuthMcpRefreshTokenService::class)->issueForCodeExchange(
            $user,
            $client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );

        return ['user' => $user, 'client' => $client, 'family' => $issue['family'], 'raw' => $issue['raw']];
    }

    public function test_refresh_token_grant_rotates_and_returns_new_tokens(): void
    {
        ['client' => $client, 'raw' => $raw] = $this->setupTokens();

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope']);
        $this->assertNotSame($raw, $response->json('refresh_token'));

        $oldHash = hash('sha256', $raw);
        $this->assertNotNull(OauthMcpRefreshToken::where('token_hash', $oldHash)->first()->used_at);
    }

    public function test_replay_of_rotated_token_returns_invalid_grant_and_revokes_family(): void
    {
        ['client' => $client, 'family' => $family, 'raw' => $raw] = $this->setupTokens();

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ])->assertStatus(200);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('reuse_detected', $family->revoked_reason);
    }

    public function test_unknown_refresh_token_returns_invalid_grant(): void
    {
        ['client' => $client] = $this->setupTokens();

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => str_repeat('z', 64),
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_unsupported_grant_type_returns_error(): void
    {
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
        ]);
        $response->assertStatus(400)->assertJson(['error' => 'unsupported_grant_type']);
    }
}

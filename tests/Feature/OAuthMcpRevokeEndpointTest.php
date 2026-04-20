<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRevokeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function issue(): array
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $issue = app(OAuthMcpRefreshTokenService::class)->issueForCodeExchange(
            $user, $client, 'https://example.test/api/mcp', 'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );

        return [$client, $issue['family'], $issue['raw']];
    }

    public function test_revoke_refresh_token_returns_200_and_revokes_family(): void
    {
        [$client, $family, $raw] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => $raw,
            'token_type_hint' => 'refresh_token',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('user', $family->revoked_reason);

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_revoke_unknown_token_returns_200(): void
    {
        [$client] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => str_repeat('z', 64),
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_revoke_access_token_hint_is_noop_200(): void
    {
        [$client] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => 'not-a-refresh-token',
            'token_type_hint' => 'access_token',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_revoke_endpoint_is_csrf_exempt(): void
    {
        [$client, , $raw] = $this->issue();

        $response = $this->post('/oauth/revoke', [
            'token' => $raw,
            'client_id' => $client->id,
        ], ['Accept' => 'application/json']);

        $this->assertNotEquals(419, $response->status());
    }
}

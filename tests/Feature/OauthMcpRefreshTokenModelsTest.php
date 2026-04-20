<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OauthMcpRefreshTokenModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_and_token_relationships(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $family = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'scope' => 'ideatub:mcp',
            'issued_at' => now(),
            'absolute_expires_at' => now()->addDays(90),
        ]);

        $token = OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($family->refreshTokens->contains($token));
        $this->assertTrue($token->family->is($family));
        $this->assertTrue($family->user->is($user));
        $this->assertTrue($family->client->is($client));
    }

    public function test_active_scope_excludes_revoked_and_absolutely_expired_families(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $active = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(30),
        ]);
        OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now()->subDays(100),
            'absolute_expires_at' => now()->subDay(),
        ]);
        OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(30),
            'revoked_at' => now(),
        ]);

        $ids = OauthMcpRefreshTokenFamily::active()->pluck('id')->all();
        $this->assertSame([$active->id], $ids);
    }

    public function test_usable_scope_excludes_used_and_expired_tokens(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $family = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(90),
        ]);

        $usable = OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDays(30),
        ]);
        OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('b', 64),
            'expires_at' => now()->subDay(),
        ]);
        OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('c', 64),
            'expires_at' => now()->addDays(30),
            'used_at' => now(),
        ]);

        $ids = OauthMcpRefreshToken::usable()->pluck('id')->all();
        $this->assertSame([$usable->id], $ids);
    }
}

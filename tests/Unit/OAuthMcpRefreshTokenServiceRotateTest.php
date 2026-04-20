<?php

namespace Tests\Unit;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class OAuthMcpRefreshTokenServiceRotateTest extends TestCase
{
    use RefreshDatabase;

    private OAuthMcpRefreshTokenService $service;

    private User $user;

    private OauthMcpClient $client;

    private OauthMcpRefreshTokenFamily $family;

    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oauth-mcp.refresh_token_ttl_seconds', 60 * 60 * 24 * 30);
        config()->set('oauth-mcp.refresh_token_absolute_lifetime_seconds', 60 * 60 * 24 * 90);

        $this->service = app(OAuthMcpRefreshTokenService::class);
        $this->user = User::factory()->create();
        $this->client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $issue = $this->service->issueForCodeExchange(
            $this->user,
            $this->client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );
        $this->family = $issue['family'];
        $this->rawToken = $issue['raw'];
    }

    public function test_rotate_issues_new_token_and_invalidates_old(): void
    {
        $oldHash = hash('sha256', $this->rawToken);

        $result = $this->service->rotate(
            $this->rawToken,
            $this->client->id,
            'https://example.test/api/mcp',
            null,
            Request::create('/oauth/token', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']),
        );

        $this->assertTrue($result['user']->is($this->user));
        $this->assertSame('https://example.test/api/mcp', $result['resource']);
        $this->assertSame('ideatub:mcp', $result['scope']);
        $this->assertIsString($result['raw']);
        $this->assertNotSame($this->rawToken, $result['raw']);

        $old = OauthMcpRefreshToken::where('token_hash', $oldHash)->firstOrFail();
        $this->assertNotNull($old->used_at);
        $this->assertNotNull($old->replaced_by_id);

        $new = OauthMcpRefreshToken::where('token_hash', hash('sha256', $result['raw']))->firstOrFail();
        $this->assertSame($this->family->id, $new->family_id);

        $this->family->refresh();
        $this->assertNotNull($this->family->last_used_at);
        $this->assertSame('10.0.0.9', $this->family->ip_address);
    }

    public function test_replay_of_rotated_token_revokes_family_with_reuse_detected(): void
    {
        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        try {
            $this->service->rotate(
                $this->rawToken, $this->client->id,
                'https://example.test/api/mcp', null,
                Request::create('/oauth/token', 'POST'),
            );
        } finally {
            $this->family->refresh();
            $this->assertNotNull($this->family->revoked_at);
            $this->assertSame('reuse_detected', $this->family->revoked_reason);
        }
    }

    public function test_unknown_token_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            str_repeat('z', 64), $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_client_mismatch_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, 'other-client-id',
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_resource_mismatch_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://other.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_revoked_family_fails_invalid_grant(): void
    {
        $this->family->update(['revoked_at' => now(), 'revoked_reason' => 'user']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_expired_token_fails_invalid_grant(): void
    {
        OauthMcpRefreshToken::query()
            ->where('family_id', $this->family->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_scope_upgrade_rejected_downgrade_allowed(): void
    {
        $result = $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', 'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );
        $this->assertSame('ideatub:mcp', $result['scope']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_scope');
        $this->service->rotate(
            $result['raw'], $this->client->id,
            'https://example.test/api/mcp', 'ideatub:mcp ideatub:admin',
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_rotate_caps_new_expires_at_at_absolute_lifetime(): void
    {
        // Move the absolute cap to just 60 seconds from now, well below the 30-day rolling TTL.
        $this->family->update(['absolute_expires_at' => now()->addSeconds(60)]);

        $result = $this->service->rotate(
            $this->rawToken,
            $this->client->id,
            'https://example.test/api/mcp',
            null,
            Request::create('/oauth/token', 'POST'),
        );

        $newHash = hash('sha256', $result['raw']);
        $newToken = OauthMcpRefreshToken::where('token_hash', $newHash)->firstOrFail();

        // New expires_at must NOT exceed the family's absolute cap.
        $this->assertTrue(
            $newToken->expires_at->lte($this->family->fresh()->absolute_expires_at),
            'new token expires_at should be capped at family.absolute_expires_at'
        );
        // And should be close to the cap, not to now+30d.
        $this->assertTrue(
            $newToken->expires_at->gt(now()->addSeconds(30)),
            'new token expires_at should still be slightly in the future'
        );
        $this->assertTrue(
            $newToken->expires_at->lt(now()->addDays(1)),
            'new token expires_at should not be 30 days out when cap is 60s out'
        );
    }

    public function test_rotate_fails_after_absolute_expiry(): void
    {
        $this->family->update(['absolute_expires_at' => now()->subSecond()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken,
            $this->client->id,
            'https://example.test/api/mcp',
            null,
            Request::create('/oauth/token', 'POST'),
        );
    }
}

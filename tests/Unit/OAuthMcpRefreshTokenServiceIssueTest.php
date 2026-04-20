<?php

namespace Tests\Unit;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRefreshTokenServiceIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_for_code_exchange_creates_family_and_first_token(): void
    {
        config()->set('oauth-mcp.refresh_token_ttl_seconds', 60 * 60 * 24 * 30);
        config()->set('oauth-mcp.refresh_token_absolute_lifetime_seconds', 60 * 60 * 24 * 90);

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $request = Request::create('/oauth/token', 'POST', server: [
            'HTTP_USER_AGENT' => 'claude-test/1.0',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        $service = app(OAuthMcpRefreshTokenService::class);

        $result = $service->issueForCodeExchange(
            $user,
            $client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            $request
        );

        $this->assertIsString($result['raw']);
        $this->assertSame(64, strlen($result['raw']));
        $this->assertInstanceOf(OauthMcpRefreshTokenFamily::class, $result['family']);

        $family = $result['family']->fresh();
        $this->assertSame($user->id, $family->user_id);
        $this->assertSame($client->id, $family->client_id);
        $this->assertSame('https://example.test/api/mcp', $family->resource);
        $this->assertSame('ideatub:mcp', $family->scope);
        $this->assertSame('claude-test/1.0', $family->user_agent);
        $this->assertSame('10.0.0.1', $family->ip_address);
        $this->assertNull($family->revoked_at);
        $this->assertTrue($family->absolute_expires_at->gt(now()->addDays(89)));

        $token = OauthMcpRefreshToken::where('family_id', $family->id)->firstOrFail();
        $this->assertSame(hash('sha256', $result['raw']), $token->token_hash);
        $this->assertNull($token->used_at);
        $this->assertTrue($token->expires_at->gt(now()->addDays(29)));
    }
}

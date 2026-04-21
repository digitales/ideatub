<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConnectedAppsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeFamily(User $user): OauthMcpRefreshTokenFamily
    {
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        return app(OAuthMcpRefreshTokenService::class)
            ->issueForCodeExchange(
                $user, $client,
                'https://example.test/api/mcp', 'ideatub:mcp',
                Request::create('/oauth/token', 'POST'),
            )['family'];
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/settings/connected-apps')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_active_families(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeFamily($user);
        $other = $this->makeFamily(User::factory()->create());
        $revoked = $this->makeFamily($user);
        app(OAuthMcpRefreshTokenService::class)->revokeFamily($revoked, 'user');

        $response = $this->actingAs($user)->get('/settings/connected-apps');

        $response->assertStatus(200);
        $response->assertSee($mine->client->redirect_uris[0]);
        $response->assertDontSee($other->id);
        $response->assertDontSee($revoked->id);
    }

    public function test_destroy_revokes_own_family(): void
    {
        $user = User::factory()->create();
        $family = $this->makeFamily($user);

        $response = $this->actingAs($user)
            ->delete('/settings/connected-apps/'.$family->id);

        $response->assertRedirect('/settings/connected-apps');

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('user', $family->revoked_reason);
    }

    public function test_destroy_returns_403_on_cross_user(): void
    {
        $user = User::factory()->create();
        $other = $this->makeFamily(User::factory()->create());

        $response = $this->actingAs($user)
            ->delete('/settings/connected-apps/'.$other->id);

        $response->assertStatus(403);

        $other->refresh();
        $this->assertNull($other->revoked_at);
    }

    public function test_destroy_all_revokes_all_own_active_families(): void
    {
        $user = User::factory()->create();
        $f1 = $this->makeFamily($user);
        $f2 = $this->makeFamily($user);
        $other = $this->makeFamily(User::factory()->create());

        $response = $this->actingAs($user)->delete('/settings/connected-apps');
        $response->assertRedirect('/settings/connected-apps');

        $this->assertNotNull($f1->fresh()->revoked_at);
        $this->assertNotNull($f2->fresh()->revoked_at);
        $this->assertNull($other->fresh()->revoked_at);
    }
}

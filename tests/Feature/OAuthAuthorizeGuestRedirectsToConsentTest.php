<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\User;
use App\Services\OAuthMcpJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthAuthorizeGuestRedirectsToConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_preserves_oauth_authorize_url_in_session(): void
    {
        config(['oauth-mcp.enabled' => true]);

        $client = OauthMcpClient::query()->create([
            'redirect_uris' => ['http://127.0.0.1:52757/callback'],
        ]);

        $query = [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => 'http://127.0.0.1:52757/callback',
            'scope' => 'ideatub:mcp',
            'state' => 'state-token',
            'code_challenge' => 'dBjftJeZ4CVP-mB92K27uhbAjr2gZwppSaLwCYrZ-yY',
            'code_challenge_method' => 'S256',
            'resource' => rtrim((string) config('app.url'), '/').'/api/mcp',
        ];

        $response = $this->get('/oauth/authorize?'.http_build_query($query));

        $response->assertRedirect(route('login'));
        $expectedIntended = url('/oauth/authorize').'?'.http_build_query([
            'response_type' => $query['response_type'],
            'client_id' => $query['client_id'],
            'redirect_uri' => $query['redirect_uri'],
            'scope' => $query['scope'],
            'state' => $query['state'],
            'code_challenge' => $query['code_challenge'],
            'code_challenge_method' => $query['code_challenge_method'],
            'resource' => $query['resource'],
        ]);
        $this->assertSame($expectedIntended, session('url.intended'));
    }

    public function test_guest_oauth_authorize_works_when_resource_param_is_omitted(): void
    {
        config(['oauth-mcp.enabled' => true]);

        $client = OauthMcpClient::query()->create([
            'redirect_uris' => ['http://127.0.0.1:52757/callback'],
        ]);

        $query = [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => 'http://127.0.0.1:52757/callback',
            'scope' => 'ideatub:mcp',
            'state' => 'state-token',
            'code_challenge' => 'dBjftJeZ4CVP-mB92K27uhbAjr2gZwppSaLwCYrZ-yY',
            'code_challenge_method' => 'S256',
        ];

        $response = $this->get('/oauth/authorize?'.http_build_query($query));

        $response->assertRedirect(route('login'));
        $intended = session('url.intended');
        $this->assertNotNull($intended);
        parse_str((string) parse_url((string) $intended, PHP_URL_QUERY), $query);
        $this->assertSame(
            OAuthMcpJwtService::normalizeResourceUrl((string) config('oauth-mcp.resource')),
            OAuthMcpJwtService::normalizeResourceUrl((string) ($query['resource'] ?? ''))
        );
    }

    public function test_after_login_guest_is_redirected_back_to_oauth_authorize(): void
    {
        config(['oauth-mcp.enabled' => true]);

        $user = User::factory()->create();

        $client = OauthMcpClient::query()->create([
            'redirect_uris' => ['http://127.0.0.1:52757/callback'],
        ]);

        $authorizeQuery = [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => 'http://127.0.0.1:52757/callback',
            'scope' => 'ideatub:mcp',
            'state' => 'state-token',
            'code_challenge' => 'dBjftJeZ4CVP-mB92K27uhbAjr2gZwppSaLwCYrZ-yY',
            'code_challenge_method' => 'S256',
            'resource' => rtrim((string) config('app.url'), '/').'/api/mcp',
        ];

        $this->get('/oauth/authorize?'.http_build_query($authorizeQuery))
            ->assertRedirect(route('login'));

        $intended = session('url.intended');
        $this->assertNotNull($intended);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect($intended);
    }
}

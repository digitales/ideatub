<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpWellKnownRefreshMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_server_metadata_advertises_refresh_and_revoke(): void
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $this->assertContains('refresh_token', $response->json('grant_types_supported'));
        $this->assertSame(
            'https://example.test/oauth/revoke',
            $response->json('revocation_endpoint')
        );
        $this->assertContains('none', $response->json('revocation_endpoint_auth_methods_supported'));
    }
}

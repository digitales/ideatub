<?php

namespace Tests\Feature;

use Tests\TestCase;

class OAuthMcpConfigTest extends TestCase
{
    public function test_config_defines_refresh_token_ttls(): void
    {
        $this->assertSame(3600, config('oauth-mcp.access_token_ttl_seconds'));
        $this->assertSame(60 * 60 * 24 * 30, config('oauth-mcp.refresh_token_ttl_seconds'));
        $this->assertSame(60 * 60 * 24 * 90, config('oauth-mcp.refresh_token_absolute_lifetime_seconds'));
    }
}

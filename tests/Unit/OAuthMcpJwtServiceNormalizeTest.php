<?php

namespace Tests\Unit;

use App\Services\OAuthMcpJwtService;
use PHPUnit\Framework\TestCase;

class OAuthMcpJwtServiceNormalizeTest extends TestCase
{
    public function test_normalizes_trailing_slash_and_scheme_host_case(): void
    {
        $this->assertSame(
            'https://ideatub.com/api/mcp',
            OAuthMcpJwtService::normalizeResourceUrl('HTTPS://IDEATUB.COM/api/mcp/')
        );
    }

    public function test_normalizes_issuer_without_path(): void
    {
        $this->assertSame(
            'https://ideatub.com',
            OAuthMcpJwtService::normalizeResourceUrl('https://ideatub.com/')
        );
    }

    public function test_preserves_non_default_port(): void
    {
        $this->assertSame(
            'http://localhost:8000/api/mcp',
            OAuthMcpJwtService::normalizeResourceUrl('http://localhost:8000/api/mcp/')
        );
    }
}

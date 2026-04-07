<?php

namespace Tests\Unit;

use App\Support\BearerTokenExtractor;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class BearerTokenExtractorTest extends TestCase
{
    public function test_extracts_from_authorization_header(): void
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer my.jwt.token',
        ]);

        $this->assertSame('my.jwt.token', BearerTokenExtractor::fromRequest($request));
    }

    public function test_extracts_from_http_authorization_server_only(): void
    {
        $request = Request::create('/api/mcp', 'POST');
        $request->server->set('HTTP_AUTHORIZATION', 'bearer  spaced.token.here');

        $this->assertSame('spaced.token.here', BearerTokenExtractor::fromRequest($request));
    }

    public function test_extracts_from_x_authorization_when_authorization_missing(): void
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], [
            'HTTP_X_AUTHORIZATION' => 'Bearer from-proxy',
        ]);

        $this->assertSame('from-proxy', BearerTokenExtractor::fromRequest($request));
    }

    public function test_extracts_raw_jwt_from_x_access_token(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.sig';
        $request = Request::create('/api/mcp', 'POST', [], [], [], [
            'HTTP_X_ACCESS_TOKEN' => $jwt,
        ]);

        $this->assertSame($jwt, BearerTokenExtractor::fromRequest($request));
    }
}

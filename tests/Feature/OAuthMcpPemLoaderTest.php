<?php

namespace Tests\Feature;

use App\Support\OAuthMcpPemLoader;
use RuntimeException;
use Tests\TestCase;

class OAuthMcpPemLoaderTest extends TestCase
{
    public function test_loads_private_key_from_base64_config(): void
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $key = openssl_pkey_new($config);
        $this->assertNotFalse($key);
        $pem = null;
        $this->assertTrue(openssl_pkey_export($key, $pem));

        config([
            'oauth-mcp.private_key_b64' => base64_encode($pem),
            'oauth-mcp.private_key_pem' => null,
            'oauth-mcp.private_key_path' => '/nonexistent/oauth-mcp-private-'.uniqid('', true).'.pem',
        ]);

        $this->assertSame($pem, OAuthMcpPemLoader::privateKey());
    }

    public function test_invalid_base64_throws(): void
    {
        config([
            'oauth-mcp.private_key_b64' => '%%%',
            'oauth-mcp.private_key_pem' => null,
            'oauth-mcp.private_key_path' => '/nonexistent/oauth-mcp-private-'.uniqid('', true).'.pem',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64');
        OAuthMcpPemLoader::privateKey();
    }
}

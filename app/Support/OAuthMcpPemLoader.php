<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Loads OAuth MCP RSA PEM material from env (for serverless / ephemeral disks) or from files.
 *
 * Precedence: *_B64 env → inline PEM env → file path from config.
 */
final class OAuthMcpPemLoader
{
    public static function privateKey(): string
    {
        return self::load(
            b64ConfigKey: 'oauth-mcp.private_key_b64',
            pemConfigKey: 'oauth-mcp.private_key_pem',
            pathConfigKey: 'oauth-mcp.private_key_path',
            missingMessage: 'OAuth MCP private key not found. Set OAUTH_MCP_PRIVATE_KEY_B64 (or OAUTH_MCP_PRIVATE_KEY), or deploy storage/app/oauth-mcp-private.pem, or run: php artisan ideatub:oauth-mcp-keys'
        );
    }

    public static function publicKey(): string
    {
        return self::load(
            b64ConfigKey: 'oauth-mcp.public_key_b64',
            pemConfigKey: 'oauth-mcp.public_key_pem',
            pathConfigKey: 'oauth-mcp.public_key_path',
            missingMessage: 'OAuth MCP public key not found. Set OAUTH_MCP_PUBLIC_KEY_B64 (or OAUTH_MCP_PUBLIC_KEY), or deploy storage/app/oauth-mcp-public.pem, or run: php artisan ideatub:oauth-mcp-keys'
        );
    }

    private static function load(string $b64ConfigKey, string $pemConfigKey, string $pathConfigKey, string $missingMessage): string
    {
        $b64 = config($b64ConfigKey);
        if (is_string($b64) && $b64 !== '') {
            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                throw new \RuntimeException('Invalid base64 in '.$b64ConfigKey);
            }

            return $decoded;
        }

        $pem = config($pemConfigKey);
        if (is_string($pem) && trim($pem) !== '') {
            return str_replace('\\n', "\n", trim($pem));
        }

        $path = config($pathConfigKey);
        if (is_string($path) && $path !== '' && File::exists($path)) {
            return File::get($path);
        }

        throw new \RuntimeException($missingMessage);
    }
}

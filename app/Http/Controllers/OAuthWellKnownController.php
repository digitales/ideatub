<?php

namespace App\Http\Controllers;

use App\Services\OAuthMcpJwtService;
use App\Support\OAuthMcpPemLoader;
use Illuminate\Http\JsonResponse;

class OAuthWellKnownController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        $resource = OAuthMcpJwtService::normalizeResourceUrl((string) config('oauth-mcp.resource'));
        $issuer = OAuthMcpJwtService::normalizeResourceUrl(rtrim((string) config('oauth-mcp.issuer'), '/'));

        return response()->json([
            'resource' => $resource,
            'authorization_servers' => [$issuer],
            'scopes_supported' => [config('oauth-mcp.scope')],
            'resource_documentation' => rtrim(config('app.url'), '/').'/help#mcp',
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        $issuer = OAuthMcpJwtService::normalizeResourceUrl(rtrim((string) config('oauth-mcp.issuer'), '/'));

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [config('oauth-mcp.scope')],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]);
    }

    public function jwks(): JsonResponse
    {
        try {
            $publicPem = OAuthMcpPemLoader::publicKey();
        } catch (\RuntimeException) {
            abort(404, 'JWKS not configured. Set OAUTH_MCP_PUBLIC_KEY_B64 or run: php artisan ideatub:oauth-mcp-keys');
        }

        $publicKey = openssl_pkey_get_public($publicPem);
        $details = openssl_pkey_get_details($publicKey);
        if (! $details || ! isset($details['key'])) {
            abort(500, 'Invalid public key');
        }

        // Build JWK from PEM (n and e from RSA public key)
        $cert = $details['key'];
        $res = openssl_pkey_get_public($cert);
        if (! $res) {
            abort(500, 'Invalid key');
        }
        $pub = openssl_pkey_get_details($res);
        if (! $pub || $pub['type'] !== OPENSSL_KEYTYPE_RSA) {
            abort(500, 'RSA key required');
        }

        $n = $pub['rsa']['n'];
        $e = $pub['rsa']['e'];
        $kid = 'ideatub-mcp-1';

        return response()->json([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => $kid,
                    'n' => rtrim(strtr(base64_encode($n), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($e), '+/', '-_'), '='),
                ],
            ],
        ]);
    }
}

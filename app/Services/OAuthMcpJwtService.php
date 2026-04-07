<?php

namespace App\Services;

use App\Models\User;
use App\Support\OAuthMcpPemLoader;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class OAuthMcpJwtService
{
    private const ALG = 'RS256';

    private const KID = 'ideatub-mcp-1';

    /**
     * Normalize OAuth resource / issuer URLs so comparisons tolerate trailing slashes
     * and scheme/host case differences (common client vs .env mismatches).
     */
    public static function normalizeResourceUrl(string $url): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($trimmed, '/');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $path = '';
        } else {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$port.($path !== '' ? $path : '');
    }

    public function issueAccessToken(User $user, string $audience): string
    {
        $issuer = self::normalizeResourceUrl(rtrim(config('oauth-mcp.issuer'), '/'));
        $audience = self::normalizeResourceUrl($audience);
        $ttl = config('oauth-mcp.access_token_ttl_seconds', 3600);
        $now = time();

        $payload = [
            'iss' => $issuer,
            'sub' => (string) $user->id,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + $ttl,
            'scope' => config('oauth-mcp.scope'),
        ];

        $privateKey = OAuthMcpPemLoader::privateKey();

        return JWT::encode($payload, $privateKey, self::ALG, self::KID);
    }

    /**
     * @return array{user_id: int, aud: string}
     *
     * @throws \Exception
     */
    public function verifyAccessToken(string $token): array
    {
        $publicKey = new Key(OAuthMcpPemLoader::publicKey(), self::ALG);

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = max(JWT::$leeway, 120);

        try {
            $decoded = JWT::decode($token, $publicKey);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $resource = self::normalizeResourceUrl((string) config('oauth-mcp.resource'));
        $resourceApi = self::normalizeResourceUrl((string) config('oauth-mcp.resource_api'));
        $allowedAudiences = array_values(array_unique(array_filter([$resource, $resourceApi])));

        $tokenAudiences = [];
        if (isset($decoded->aud)) {
            if (is_string($decoded->aud)) {
                $tokenAudiences[] = $decoded->aud;
            } elseif (is_array($decoded->aud)) {
                $tokenAudiences = $decoded->aud;
            }
        }

        $audienceOk = false;
        foreach ($tokenAudiences as $aud) {
            if (! is_string($aud)) {
                continue;
            }
            if (in_array(self::normalizeResourceUrl($aud), $allowedAudiences, true)) {
                $audienceOk = true;
                break;
            }
        }

        if (! $audienceOk) {
            throw new \Exception('Invalid audience');
        }

        $issuer = self::normalizeResourceUrl(rtrim(config('oauth-mcp.issuer'), '/'));
        $tokenIssuer = self::normalizeResourceUrl(rtrim((string) $decoded->iss, '/'));
        if ($tokenIssuer !== $issuer) {
            throw new \Exception('Invalid issuer');
        }

        return [
            'user_id' => (int) $decoded->sub,
            'aud' => $decoded->aud,
        ];
    }
}

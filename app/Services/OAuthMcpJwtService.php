<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\File;

class OAuthMcpJwtService
{
    private const ALG = 'RS256';

    private const KID = 'ideatub-mcp-1';

    public function issueAccessToken(User $user, string $audience): string
    {
        $issuer = rtrim(config('oauth-mcp.issuer'), '/');
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

        $keyPath = config('oauth-mcp.private_key_path');
        if (! File::exists($keyPath)) {
            throw new \RuntimeException('OAuth MCP private key not found. Run: php artisan ideatub:oauth-mcp-keys');
        }

        $privateKey = File::get($keyPath);

        return JWT::encode($payload, $privateKey, self::ALG, self::KID);
    }

    /**
     * @return array{user_id: int, aud: string}
     *
     * @throws \Exception
     */
    public function verifyAccessToken(string $token): array
    {
        $keyPath = config('oauth-mcp.public_key_path');
        if (! File::exists($keyPath)) {
            throw new \RuntimeException('OAuth MCP public key not found.');
        }

        $publicKey = new Key(File::get($keyPath), self::ALG);
        $decoded = JWT::decode($token, $publicKey);

        $resource = config('oauth-mcp.resource');
        $resourceApi = config('oauth-mcp.resource_api');
        $allowedAudiences = array_filter([$resource, $resourceApi]);
        if (! in_array($decoded->aud, $allowedAudiences, true)) {
            throw new \Exception('Invalid audience');
        }

        $issuer = rtrim(config('oauth-mcp.issuer'), '/');
        if ($decoded->iss !== $issuer) {
            throw new \Exception('Invalid issuer');
        }

        return [
            'user_id' => (int) $decoded->sub,
            'aud' => $decoded->aud,
        ];
    }
}

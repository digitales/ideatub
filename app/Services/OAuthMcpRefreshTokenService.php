<?php

namespace App\Services;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthMcpRefreshTokenService
{
    /**
     * Create a new token family + its first refresh token row.
     *
     * @return array{family: OauthMcpRefreshTokenFamily, raw: string}
     */
    public function issueForCodeExchange(
        User $user,
        OauthMcpClient $client,
        string $resource,
        ?string $scope,
        Request $request,
    ): array {
        $now = Carbon::now();
        $absoluteCap = $now->copy()->addSeconds((int) config('oauth-mcp.refresh_token_absolute_lifetime_seconds'));

        return DB::transaction(function () use ($user, $client, $resource, $scope, $request, $now, $absoluteCap) {
            $family = OauthMcpRefreshTokenFamily::create([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'resource' => OAuthMcpJwtService::normalizeResourceUrl($resource),
                'scope' => $scope,
                'user_agent' => $this->truncate($request->userAgent(), 512),
                'ip_address' => $request->ip(),
                'last_used_at' => null,
                'issued_at' => $now,
                'absolute_expires_at' => $absoluteCap,
            ]);

            $raw = Str::random(64);

            OauthMcpRefreshToken::create([
                'family_id' => $family->id,
                'token_hash' => hash('sha256', $raw),
                'expires_at' => $this->cappedRefreshExpiry($now, $family),
            ]);

            return ['family' => $family, 'raw' => $raw];
        });
    }

    private function cappedRefreshExpiry(Carbon $now, OauthMcpRefreshTokenFamily $family): Carbon
    {
        $rolling = $now->copy()->addSeconds((int) config('oauth-mcp.refresh_token_ttl_seconds'));

        return $rolling->lt($family->absolute_expires_at) ? $rolling : $family->absolute_expires_at->copy();
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}

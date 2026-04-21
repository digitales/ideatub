<?php

namespace App\Services;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Validate + rotate a refresh token. Revokes the family on replay.
     *
     * @return array{user: \App\Models\User, resource: string, scope: ?string, raw: string}
     */
    public function rotate(
        string $rawToken,
        string $clientId,
        string $resource,
        ?string $requestedScope,
        Request $request,
    ): array {
        $hash = hash('sha256', $rawToken);
        $normalizedResource = OAuthMcpJwtService::normalizeResourceUrl($resource);

        $startLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $token = OauthMcpRefreshToken::where('token_hash', $hash)->lockForUpdate()->first();
            if (! $token) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            $family = OauthMcpRefreshTokenFamily::lockForUpdate()->find($token->family_id);
            if (! $family) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($family->revoked_at !== null || now()->gt($family->absolute_expires_at)) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($token->used_at !== null) {
                // Reuse detected — burn the family and commit that revocation before raising.
                Log::warning('oauth.mcp.refresh.reuse_detected', [
                    'family_id' => $family->id,
                    'user_id' => $family->user_id,
                    'client_id' => $family->client_id,
                ]);
                $this->revokeFamily($family, 'reuse_detected');
                DB::commit();
                throw new \RuntimeException('invalid_grant');
            }

            if ($family->client_id !== $clientId) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($family->resource !== $normalizedResource) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if (now()->gt($token->expires_at)) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            try {
                $this->validateScopeSubset($family->scope, $requestedScope);
            } catch (\RuntimeException $e) {
                DB::rollBack();
                throw $e;
            }

            $effectiveScope = $requestedScope ?? $family->scope;

            $now = now();
            $newRaw = Str::random(64);

            $new = OauthMcpRefreshToken::create([
                'family_id' => $family->id,
                'token_hash' => hash('sha256', $newRaw),
                'expires_at' => $this->cappedRefreshExpiry($now, $family),
            ]);

            $token->update([
                'used_at' => $now,
                'replaced_by_id' => $new->id,
            ]);

            $family->update([
                'last_used_at' => $now,
                'user_agent' => $this->truncate($request->userAgent(), 512) ?? $family->user_agent,
                'ip_address' => $request->ip() ?? $family->ip_address,
            ]);

            Log::info('oauth.mcp.refresh.success', [
                'family_id' => $family->id,
                'user_id' => $family->user_id,
                'client_id' => $family->client_id,
            ]);
            DB::commit();

            return [
                'user' => $family->user,
                'resource' => $family->resource,
                'scope' => $effectiveScope,
                'raw' => $newRaw,
            ];
        } catch (\Throwable $e) {
            // Only roll back savepoints WE created. Never touch the caller's transaction.
            while (DB::transactionLevel() > $startLevel) {
                try {
                    DB::rollBack();
                } catch (\Throwable $ignored) {
                    break;
                }
            }
            throw $e;
        }
    }

    public function revokeFamily(OauthMcpRefreshTokenFamily $family, string $reason): void
    {
        if ($family->revoked_at !== null) {
            return;
        }

        $family->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function revokeByRawToken(string $rawToken, string $reason, ?string $clientId = null): void
    {
        $hash = hash('sha256', $rawToken);
        $token = OauthMcpRefreshToken::where('token_hash', $hash)->first();
        if (! $token) {
            return;
        }
        $family = $token->family;
        if ($clientId !== null && $family->client_id !== $clientId) {
            return;
        }
        $this->revokeFamily($family, $reason);
    }

    private function validateScopeSubset(?string $familyScope, ?string $requestedScope): void
    {
        if ($requestedScope === null || $requestedScope === '') {
            return;
        }

        $family = array_filter(explode(' ', (string) $familyScope));
        $requested = array_filter(explode(' ', $requestedScope));

        foreach ($requested as $scope) {
            if (! in_array($scope, $family, true)) {
                throw new \RuntimeException('invalid_scope');
            }
        }
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

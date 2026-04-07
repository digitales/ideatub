<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class McpSessionService
{
    public function create(int $userId): string
    {
        $id = Str::uuid()->toString();
        $ttl = max(60, (int) config('mcp.session_ttl_seconds', 86400));
        Cache::put($this->cacheKey($id), $userId, $ttl);

        return $id;
    }

    public function userId(?string $sessionId): ?int
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        $value = Cache::get($this->cacheKey($sessionId));

        return is_int($value) ? $value : null;
    }

    public function destroy(?string $sessionId): void
    {
        if (is_string($sessionId) && $sessionId !== '') {
            Cache::forget($this->cacheKey($sessionId));
        }
    }

    private function cacheKey(string $sessionId): string
    {
        return 'mcp:session:'.$sessionId;
    }
}

<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Extracts OAuth access tokens from common Authorization / proxy header locations.
 */
final class BearerTokenExtractor
{
    /**
     * Raw Bearer token string, or null if none found.
     */
    public static function fromRequest(Request $request): ?string
    {
        $candidates = [
            $request->header('Authorization'),
            $request->server('HTTP_AUTHORIZATION'),
            $request->server('REDIRECT_HTTP_AUTHORIZATION'),
            $request->header('X-Authorization'),
            $request->header('X-Forwarded-Authorization'),
        ];

        foreach ($candidates as $auth) {
            if (! is_string($auth) || trim($auth) === '') {
                continue;
            }
            if (preg_match('/^\s*Bearer\s+(\S+)/i', $auth, $matches)) {
                $token = trim($matches[1]);
                if ($token !== '') {
                    return $token;
                }
            }
        }

        // Raw JWT (no "Bearer " prefix) — some proxies strip Authorization and forward here.
        foreach (['X-Access-Token', 'X-MCP-Access-Token', 'MCP-Access-Token'] as $headerName) {
            $raw = $request->header($headerName);
            if (is_string($raw)) {
                $raw = trim($raw);
                if ($raw !== '' && str_contains($raw, '.')) {
                    return $raw;
                }
            }
        }

        return null;
    }
}

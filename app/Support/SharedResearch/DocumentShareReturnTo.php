<?php

namespace App\Support\SharedResearch;

final class DocumentShareReturnTo
{
    /** @var list<string> */
    private const ALLOWED_PATH_PREFIXES = [
        '/stream',
        '/thoughts/',
    ];

    public static function resolve(?string $returnTo): ?string
    {
        if ($returnTo === null || trim($returnTo) === '') {
            return null;
        }

        $parsed = parse_url($returnTo);

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (isset($parsed['host']) && is_string($appHost) && $parsed['host'] !== $appHost) {
            return null;
        }

        $path = $parsed['path'] ?? null;
        if ($path === null || $path === '') {
            if (! isset($parsed['host']) || (is_string($appHost) && $parsed['host'] === $appHost)) {
                return url('/');
            }

            return null;
        }

        if ($path === '/') {
            return url('/');
        }

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $query = isset($parsed['query']) && $parsed['query'] !== ''
                    ? '?'.$parsed['query']
                    : '';

                return url($path).$query;
            }
        }

        return null;
    }
}

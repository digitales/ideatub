<?php

namespace App\Services\Import;

/**
 * Rewrites in-batch relative .md links to query form (?page=segment) and counts local (non-URL) image refs.
 * Rewrites absolute IdeaTub `/reports/{slug}/{page}` URLs when `{page}` matches a batch segment (published microsite URLs).
 */
final class MicrositeMarkdownLinkRewriter
{
    private const BRACKET_LINK = '/!?\[([^\]]*)\]\(([^)]+)\)/';

    /**
     * @param  array<string, string>  $pathSegmentByPathKey  MicrositeImportService: normalised lowercase path => page_path_segment
     * @return array{markdown: string, localAssetRefCount: int}
     */
    public function rewrite(
        string $markdown,
        string $fromRelativePath,
        array $pathSegmentByPathKey,
    ): array {
        $fromRelativePath = str_replace('\\', '/', (string) $fromRelativePath);
        $localAssets = 0;

        $out = preg_replace_callback(
            self::BRACKET_LINK,
            function (array $m) use ($fromRelativePath, $pathSegmentByPathKey, &$localAssets) {
                $isImage = str_starts_with($m[0], '!');
                $label = $m[1];
                $inner = (string) $m[2];
                $target = $this->unquoteUrl($inner);
                if ($isImage) {
                    if (! $this->isRemoteOrSpecial($target) && $target !== '') {
                        $localAssets++;
                    }

                    return $m[0];
                }

                if ($target === '') {
                    return $m[0];
                }

                if ($this->isRemoteOrSpecial($target)) {
                    $portable = $this->ideatubReportsUrlToPageQuery($target, $pathSegmentByPathKey);
                    if ($portable !== null) {
                        return '['.$label.']('.$portable.')';
                    }

                    return $m[0];
                }

                $pathPart = explode('#', $target, 2)[0];
                if ($pathPart === '') {
                    return $m[0];
                }
                if (! str_ends_with(strtolower($pathPart), '.md')) {
                    return $m[0];
                }
                $resolved = $this->normaliseJoin($fromRelativePath, $pathPart);
                if ($resolved === null) {
                    return $m[0];
                }
                $key = $this->pathKeyForRelativePath($resolved);
                if (! isset($pathSegmentByPathKey[$key])) {
                    return $m[0];
                }
                $page = (string) $pathSegmentByPathKey[$key];

                return '['.$label.'](?page='.rawurlencode($page).')';
            },
            $markdown
        );

        return [
            'markdown' => (string) $out,
            'localAssetRefCount' => $localAssets,
        ];
    }

    public function countAllLocalAssetRefsInBatch(array $perFileCounts): int
    {
        return (int) array_sum($perFileCounts);
    }

    private function unquoteUrl(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '<') && str_ends_with($s, '>')) {
            $s = substr($s, 1, -1);
        } elseif (strlen($s) > 1 && $s[0] === $s[strlen($s) - 1] && ($s[0] === '"' || $s[0] === "'")) {
            $s = substr($s, 1, -1);
        }

        return (string) $s;
    }

    private function isRemoteOrSpecial(string $s): bool
    {
        $l = mb_strtolower($s);

        return str_starts_with($l, 'http://')
            || str_starts_with($l, 'https://')
            || str_starts_with($l, 'mailto:')
            || str_starts_with($l, 'tel:')
            || str_starts_with($l, 'data:');
    }

    /**
     * If URL is IdeaTub `/reports/{reportSlug}/{pageSegment}` (or deeper under `/reports/…`) and the last
     * segment matches a batch page segment, return portable `?page=…` plus `#fragment` for view-layer rewriting.
     */
    private function ideatubReportsUrlToPageQuery(string $target, array $pathSegmentByPathKey): ?string
    {
        $parsed = parse_url($target);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $scheme = mb_strtolower((string) $parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (! $this->hostMatchesIdeatubFrontend(mb_strtolower((string) $parsed['host']))) {
            return null;
        }
        $path = isset($parsed['path']) ? (string) $parsed['path'] : '/';
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        if ($segments === [] || mb_strtolower($segments[0]) !== 'reports') {
            return null;
        }
        $tail = array_slice($segments, 1);
        if (count($tail) < 2) {
            return null;
        }
        $encodedCandidate = (string) $tail[count($tail) - 1];
        $candidate = rawurldecode($encodedCandidate);
        $canonicalByLower = [];
        foreach ($pathSegmentByPathKey as $segment) {
            $canonicalByLower[mb_strtolower((string) $segment)] = (string) $segment;
        }
        $lookup = mb_strtolower($candidate);
        if (! isset($canonicalByLower[$lookup])) {
            return null;
        }
        $page = $canonicalByLower[$lookup];
        $frag = isset($parsed['fragment']) && $parsed['fragment'] !== ''
            ? '#'.$parsed['fragment']
            : '';

        return '?page='.rawurlencode($page).$frag;
    }

    private function hostMatchesIdeatubFrontend(string $host): bool
    {
        if (preg_match('/^(www\.)?ideatub\.com$/', $host) === 1) {
            return true;
        }
        $appUrl = config('app.url');
        if ($appUrl !== null && $appUrl !== '') {
            $appHost = parse_url((string) $appUrl, PHP_URL_HOST);
            if ($appHost !== null && $appHost !== '' && strcasecmp($host, (string) $appHost) === 0) {
                return true;
            }
        }

        return false;
    }

    private function normaliseJoin(string $fromRelativePath, string $ref): ?string
    {
        $ref = str_replace('\\', '/', (string) $ref);
        if (str_starts_with($ref, '/')) {
            $ref = ltrim($ref, '/');
        }
        if ($ref === '') {
            return null;
        }

        $dir = dirname($fromRelativePath);
        if ($dir === '.' || $dir === '') {
            $base = $ref;
        } else {
            $base = $dir.'/'.$ref;
        }
        $norm = $this->normalisePathSegments($base);
        if ($norm === null || $norm === '') {
            return null;
        }
        if (str_contains($norm, '..') || str_starts_with($norm, '/')) {
            return null;
        }

        return $norm;
    }

    private function normalisePathSegments(string $path): ?string
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));
        if ($path === '' || $path === '.' || $path === '..') {
            return $path;
        }
        $parts = explode('/', $path);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($out) < 1) {
                    return null;
                }
                array_pop($out);
            } else {
                $out[] = $part;
            }
        }

        return implode('/', $out);
    }

    public function pathKeyForRelativePath(string $relativePath): string
    {
        $k = $this->normalisePathSegments(str_replace('\\', '/', $relativePath));
        if ($k === null) {
            return '';
        }

        return mb_strtolower($k);
    }
}

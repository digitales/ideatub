<?php

namespace App\Services\Import;

/**
 * Rewrites in-batch relative .md links to query form (?page=segment) and counts local (non-URL) image refs.
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

                if ($this->isRemoteOrSpecial($target) || $target === '') {
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

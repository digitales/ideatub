<?php

namespace App\Services\Import;

/**
 * Helpers for strict "NN-title" microsite page filenames in folder import.
 */
final class MicrositeFilename
{
    private const PATTERN = '/^(\d+)([-._])(.+)$/S';

    public static function isValidPageBasename(string $basenameNoExt): bool
    {
        if (preg_match(self::PATTERN, $basenameNoExt, $m) !== 1) {
            return false;
        }

        return $m[3] !== '' && $m[3] !== null;
    }

    /**
     * Un-normalised string from the file (e.g. 00-summary). Used in URLs and dedupe of nav.
     */
    public static function pagePathSegmentFromBasename(string $basenameNoExt): string
    {
        return $basenameNoExt;
    }

    public static function basenameFromRelativePath(string $relativePath): string
    {
        $path = str_replace('\\', '/', $relativePath);
        $base = basename($path);
        $noExt = pathinfo($base, PATHINFO_FILENAME);

        return (string) $noExt;
    }

    public static function parseSortKeyFromBasename(string $basenameNoExt): int
    {
        if (preg_match(self::PATTERN, $basenameNoExt, $m) === 1) {
            return (int) $m[1];
        }

        return \PHP_INT_MAX;
    }

    /**
     * @param  list<string>  $segments
     */
    public static function hasDuplicatePathSegments(array $segments): bool
    {
        if ($segments === []) {
            return false;
        }

        return count($segments) !== count(array_unique($segments, SORT_STRING));
    }

    /**
     * @param  list<array{relative_path: string}>  $rows
     * @return list<array{relative_path: string, page_path_segment: string, sort_key: int, basename: string}>
     */
    public static function sortedSiteRowsFromRelativePaths(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $path = (string) ($row['relative_path'] ?? '');
            if ($path === '') {
                continue;
            }
            $basename = self::basenameFromRelativePath($path);
            if (! self::isValidPageBasename($basename)) {
                continue;
            }
            $sortKey = self::parseSortKeyFromBasename($basename);
            $pagePath = self::pagePathSegmentFromBasename($basename);
            $mapped[] = [
                'relative_path' => $path,
                'page_path_segment' => $pagePath,
                'sort_key' => $sortKey,
                'basename' => $basename,
            ];
        }

        usort($mapped, function (array $a, array $b): int {
            if ($a['sort_key'] !== $b['sort_key']) {
                return $a['sort_key'] <=> $b['sort_key'];
            }

            return strcmp($a['basename'], $b['basename']);
        });

        return $mapped;
    }
}

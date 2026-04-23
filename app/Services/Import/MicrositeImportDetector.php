<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;

/**
 * Decides whether a file batch should use the research microsite import path (strict N-title .md set).
 */
final class MicrositeImportDetector
{
    private const MD_EXTENSIONS = ['md', 'mdown', 'markdown'];

    /**
     * True when this batch is a strict microsite: at least two files, all markdown by extension,
     * every file basename matches the numbered page pattern, and page_path_segment values are unique.
     *
     * @param  list<string>  $relativePaths
     * @param  array<int, UploadedFile>  $files
     */
    public static function shouldUseMicrosite(array $relativePaths, array $files): bool
    {
        if (count($files) < 2) {
            return false;
        }

        if (count($relativePaths) !== count($files)) {
            return false;
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                return false;
            }
            if (! self::isMarkdownExtension($file)) {
                return false;
            }
        }

        $segments = [];
        foreach ($relativePaths as $i => $path) {
            if (! is_string($path)) {
                return false;
            }
            $basename = MicrositeFilename::basenameFromRelativePath($path);
            if (! MicrositeFilename::isValidPageBasename($basename)) {
                return false;
            }
            $segments[] = MicrositeFilename::pagePathSegmentFromBasename($basename);
        }

        if (MicrositeFilename::hasDuplicatePathSegments($segments)) {
            return false;
        }

        return true;
    }

    /**
     * True when every file is markdown, count ≥ 2, and every basename matches the page pattern
     * (ignoring segment uniqueness). Used to reject duplicate page names with a specific error.
     *
     * @param  list<string>  $relativePaths
     * @param  array<int, UploadedFile>  $files
     */
    public static function isMicrositeShapedSet(array $relativePaths, array $files): bool
    {
        if (count($files) < 2) {
            return false;
        }
        if (count($relativePaths) !== count($files)) {
            return false;
        }
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                return false;
            }
            if (! self::isMarkdownExtension($file)) {
                return false;
            }
        }
        foreach ($relativePaths as $path) {
            if (! is_string($path)) {
                return false;
            }
            $basename = MicrositeFilename::basenameFromRelativePath($path);
            if (! MicrositeFilename::isValidPageBasename($basename)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $relativePaths
     * @param  array<int, UploadedFile>  $files
     */
    public static function hasDuplicatePagePathSegments(
        array $relativePaths,
        array $files,
    ): bool {
        if (! self::isMicrositeShapedSet($relativePaths, $files)) {
            return false;
        }
        $segments = [];
        foreach ($relativePaths as $path) {
            $basename = MicrositeFilename::basenameFromRelativePath($path);
            $segments[] = MicrositeFilename::pagePathSegmentFromBasename($basename);
        }

        return MicrositeFilename::hasDuplicatePathSegments($segments);
    }

    private static function isMarkdownExtension(UploadedFile $file): bool
    {
        $ext = mb_strtolower($file->getClientOriginalExtension());

        return in_array($ext, self::MD_EXTENSIONS, true);
    }
}

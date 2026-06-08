<?php

namespace App\Support\Inbox;

use Illuminate\Support\Str;

/**
 * Formats weekly-revisit inbox bodies so markdown list items stay scannable:
 * strip heading markers that break out as document-level h2s, collapse noise, and cap length.
 */
final class WeeklyRevisitBodyFormatter
{
    private const PREVIEW_LIMIT = 200;

    public static function formatIdeaPreview(string $content, int $limit = self::PREVIEW_LIMIT): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $parts = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) preg_replace('/^#+\s*/u', '', trim($line)));
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));

        return Str::limit($text, $limit);
    }

    /**
     * Re-normalize a stored weekly-revisit body (e.g. legacy items with multiline markdown per bullet).
     */
    public static function sanitizeStoredBody(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $result = [];
        $currentBullet = null;

        foreach ($lines as $line) {
            if (preg_match('/^-\s+(.*)$/u', $line, $matches)) {
                if ($currentBullet !== null) {
                    $result[] = '- '.$currentBullet;
                }
                $currentBullet = self::formatIdeaPreview($matches[1]);

                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($currentBullet === null) {
                $result[] = $line;
            } else {
                $currentBullet = trim($currentBullet.' '.self::formatIdeaPreview($line));
                $currentBullet = Str::limit($currentBullet, self::PREVIEW_LIMIT);
            }
        }

        if ($currentBullet !== null) {
            $result[] = '- '.$currentBullet;
        }

        return implode("\n", $result);
    }
}

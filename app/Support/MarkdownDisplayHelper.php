<?php

namespace App\Support;

/**
 * Normalises Markdown before CommonMark so display matches author intent (e.g. Jekyll/VitePress front matter).
 */
final class MarkdownDisplayHelper
{
    /** @var string Keys commonly used in static-site front matter; avoids stripping arbitrary "Key: value" prose. */
    private const FRONT_MATTER_LINE_KEYS = 'layout|title|description|permalink|sidebar_position|nav_order|sidebar_label|lang';

    public static function stripPreambleForMarkdownDisplay(string $raw): string
    {
        $s = $raw;
        if (preg_match('/\A<!--.*?-->\s*/s', $s, $m)) {
            $s = substr($s, strlen($m[0]));
        }
        // Multiline YAML between --- lines ( League / Jekyll / VitePress style )
        if (preg_match('/\A---\s*\R.*?\R---\s*\R/s', $s, $m)) {
            $s = substr($s, strlen($m[0]));
        }
        $s = self::stripLeadingFrontMatterKeyLines($s);
        $s = self::stripSingleLineConcatenatedFrontMatter($s);

        return $s;
    }

    private static function stripLeadingFrontMatterKeyLines(string $s): string
    {
        $normalized = str_replace("\r\n", "\n", $s);
        $lines = explode("\n", $normalized);
        $i = 0;
        $max = count($lines);
        $strippedAny = false;
        while ($i < $max) {
            $line = $lines[$i];
            if ($line === '') {
                if ($strippedAny) {
                    $i++;
                }

                break;
            }
            if (preg_match('/^[#>`]/', $line)) {
                break;
            }
            if (preg_match('/^---\s*$/', $line)) {
                break;
            }
            $pattern = '/^('.self::FRONT_MATTER_LINE_KEYS.'):\s*.+$/';
            if (preg_match($pattern, $line)) {
                $strippedAny = true;
                $i++;

                continue;
            }

            break;
        }
        if (! $strippedAny) {
            return $s;
        }

        $rest = array_slice($lines, $i);

        return ltrim(implode("\n", $rest), "\n");
    }

    /**
     * Some imports flatten front matter onto one line: "layout: doc title: … description: …".
     */
    private static function stripSingleLineConcatenatedFrontMatter(string $s): string
    {
        $normalized = str_replace("\r\n", "\n", $s);
        $lines = explode("\n", $normalized, 2);
        $first = trim((string) ($lines[0] ?? ''));
        $rest = $lines[1] ?? '';

        if ($first === '') {
            return $s;
        }

        $patterns = [
            '/\Alayout:\s+\S+\s+title:\s+.+\s+description:\s+.+\z/u',
            '/\Atitle:\s+.+\s+description:\s+.+\z/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $first)) {
                return ltrim($rest, "\n");
            }
        }

        return $s;
    }
}

<?php

namespace App\Support\Research;

use App\Models\Thought;
use Illuminate\Support\Str;

final class MicrositePageLabel
{
    public static function forThought(Thought $t): string
    {
        if (preg_match('/^#+\s+(.+)$/m', ltrim((string) $t->content), $m)) {
            return Str::limit(trim($m[1], " \t"), 64);
        }
        $s = (string) data_get($t->source_metadata, 'page_path_segment', 'Page');
        $s = (string) preg_replace('/^\d+[-._]/', '', $s);
        if ($s === '' || $s === '0') {
            $s = 'Page';
        }

        return (string) Str::headline(str_replace(['-', '_', '.'], ' ', $s));
    }
}

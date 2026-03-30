<?php

namespace App\Support;

final class TagSlug
{
    public static function from(string $tag): string
    {
        $normalized = mb_strtolower(trim($tag));
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $normalized);

        return preg_replace('/^_+|_+$/', '', $slug ?? '') ?? '';
    }
}

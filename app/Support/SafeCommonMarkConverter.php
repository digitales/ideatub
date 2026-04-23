<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Shared GitHub-Flavoured Markdown pipeline, aligned with
 * {@see \Illuminate\Support\Str::markdown()} (includes pipe tables, task lists, autolink, etc.).
 * Options: {@see https://commonmark.thephpleague.com/2.8/security/}
 */
final class SafeCommonMarkConverter
{
    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ];
    }

    public static function make(): GithubFlavoredMarkdownConverter
    {
        return new GithubFlavoredMarkdownConverter(self::config());
    }
}

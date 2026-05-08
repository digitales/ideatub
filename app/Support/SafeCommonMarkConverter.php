<?php

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Shared GitHub-Flavoured Markdown pipeline, aligned with
 * {@see Str::markdown()} (includes pipe tables, task lists, autolink, etc.).
 * Options: {@see https://commonmark.thephpleague.com/2.8/security/}
 */
final class SafeCommonMarkConverter
{
    /**
     * Server-side markdown to HTML for trusted content only. Strip raw HTML in source and block unsafe link schemes.
     * Prefer the safe-markdown Blade component so templates avoid scattering raw unescaped output.
     */
    public static function toHtml(?string $markdown): string
    {
        return Str::markdown((string) ($markdown ?? ''), self::config());
    }

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

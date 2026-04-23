<?php

namespace App\Support\Research;

use App\Models\Thought;

/**
 * Stored microsite markdown may use portable (?page=segment) links; HTML output uses
 * canonical /research/{root}/p/{segment} URLs in-app.
 */
final class MicrositeInAppPathHelper
{
    public static function rewriteQueryPageLinksInHtml(Thought $root, string $html): string
    {
        if ($html === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '/href="(\?page=)([^"]*)"/',
            function (array $m) use ($root) {
                $page = rawurldecode((string) $m[2]);
                $url = $page === '' || $page === '0'
                    ? route('idea.research.show', $root, true)
                    : route('idea.research.page', [
                        'thought' => $root,
                        'page' => $page,
                    ], true);

                return 'href="'.e($url).'"';
            },
            $html
        );
    }
}

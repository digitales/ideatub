<?php

namespace App\Support\Research;

/**
 * Stored or rendered HTML may use portable `href="?page=…"`; rewrites to public /r/{token} and /r/{token}/p/{page} URLs.
 */
final class MicrositeSharedUrlHelper
{
    public static function rewriteInAppQueryLinksInHtmlForShare(string $html, string $token): string
    {
        return (string) preg_replace_callback(
            '/href="(\?page=)([^"]+)"/',
            function (array $m) {
                $enc = (string) $m[2];
                $page = rawurldecode($enc);
                if ($page === '' || $page === '0') {
                    $url = route('shared-research.show', ['token' => $token], true);
                } else {
                    $url = route('shared-research.page', ['token' => $token, 'page' => $page], true);
                }

                return 'href="'.e($url).'"';
            },
            $html
        );
    }
}

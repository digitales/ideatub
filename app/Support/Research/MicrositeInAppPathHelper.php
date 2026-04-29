<?php

namespace App\Support\Research;

use App\Models\Thought;
use App\Services\Import\MicrositeMarkdownLinkRewriter;

/**
 * Stored microsite markdown may use portable (?page=segment) links; HTML output uses
 * canonical /research/{root}/p/{segment} URLs in-app.
 *
 * GFM autolinks bare https:// URLs to &lt;a href="https://…"&gt;; those never pass through
 * {@see MicrositeMarkdownLinkRewriter}. After CommonMark, rewrite remaining
 * IdeaTub /reports/… and matching /research/{thisRoot}/… hrefs to named routes.
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

    /**
     * Rewrite absolute IdeaTub microsite URLs in rendered HTML (e.g. GFM autolinks from /reports/…).
     */
    public static function rewritePublishedIdeatubLinksInHtml(Thought $root, string $html): string
    {
        return self::rewritePublishedIdeatubHrefAttributes($root, $html, null);
    }

    /**
     * Same as {@see rewritePublishedIdeatubLinksInHtml} but targets shared /r/{token} URLs.
     */
    public static function rewritePublishedIdeatubLinksInHtmlForShare(Thought $root, string $token, string $html): string
    {
        return self::rewritePublishedIdeatubHrefAttributes($root, $html, $token);
    }

    private static function rewritePublishedIdeatubHrefAttributes(Thought $root, string $html, ?string $shareToken): string
    {
        if ($html === '') {
            return $html;
        }

        $canonicalByLower = self::canonicalPageSegmentsByLower($root);
        if ($canonicalByLower === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/href="([^"]+)"/',
            function (array $m) use ($root, $canonicalByLower, $shareToken) {
                $rawHref = (string) $m[1];
                $decoded = html_entity_decode($rawHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $new = self::resolvePublishedIdeatubHrefTarget($root, $decoded, $canonicalByLower, $shareToken);
                if ($new === null) {
                    return $m[0];
                }

                return 'href="'.e($new).'"';
            },
            $html
        );
    }

    /**
     * @return array<string, string> lowercase segment => canonical segment
     */
    private static function canonicalPageSegmentsByLower(Thought $root): array
    {
        $map = [];
        foreach (collect([$root])->merge($root->micrositePageChildrenInOrder()) as $t) {
            $s = (string) data_get($t->source_metadata, 'page_path_segment', '');
            if ($s !== '') {
                $map[mb_strtolower($s)] = $s;
            }
        }

        return $map;
    }

    private static function resolvePublishedIdeatubHrefTarget(
        Thought $root,
        string $url,
        array $canonicalByLower,
        ?string $shareToken,
    ): ?string {
        $absoluteForParse = self::absolutizeIdeatubHrefForParsing($url);
        if ($absoluteForParse === null) {
            return null;
        }

        $parsed = parse_url($absoluteForParse);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }
        $scheme = mb_strtolower((string) $parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (! self::hostIsIdeatubFrontend(mb_strtolower((string) $parsed['host']))) {
            return null;
        }

        $path = isset($parsed['path']) ? trim(str_replace('\\', '/', (string) $parsed['path']), '/') : '';
        if ($path === '') {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $fragSuffix = isset($parsed['fragment']) && $parsed['fragment'] !== ''
            ? '#'.$parsed['fragment']
            : '';

        // /reports/{slug}/{pageSegment} — last segment names the microsite page.
        if (isset($segments[0]) && mb_strtolower($segments[0]) === 'reports' && count($segments) >= 3) {
            $candidate = rawurldecode((string) $segments[count($segments) - 1]);
            $page = self::canonicalSegmentOrNull($candidate, $canonicalByLower);
            if ($page === null) {
                return null;
            }

            return self::absoluteUrlForMicrositePage($root, $shareToken, $page).$fragSuffix;
        }

        $rootUuid = self::normalizeUuidForComparison((string) $root->id);

        // /research/{uuid}/p/{page}
        if (
            isset($segments[0]) && mb_strtolower($segments[0]) === 'research'
            && count($segments) >= 4
            && mb_strtolower($segments[2]) === 'p'
        ) {
            $urlUuid = self::normalizeUuidForComparison((string) $segments[1]);
            if ($urlUuid !== $rootUuid) {
                return null;
            }
            $candidate = rawurldecode((string) $segments[3]);
            $page = self::canonicalSegmentOrNull($candidate, $canonicalByLower);
            if ($page === null) {
                return null;
            }

            return self::absoluteUrlForMicrositePage($root, $shareToken, $page).$fragSuffix;
        }

        // /research/{uuid} — landing for this root only
        if (count($segments) === 2 && mb_strtolower($segments[0]) === 'research') {
            $urlUuid = self::normalizeUuidForComparison((string) $segments[1]);
            if ($urlUuid !== $rootUuid) {
                return null;
            }

            return self::absoluteUrlForMicrositeRoot($root, $shareToken).$fragSuffix;
        }

        return null;
    }

    /**
     * CommonMark turns `[label](/reports/…)` into href="/reports/…" (no scheme). Browsers resolve it to
     * the full origin URL; our rewrite logic needs an absolute URL for parse_url().
     */
    private static function absolutizeIdeatubHrefForParsing(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (str_starts_with($href, '/')) {
            $base = rtrim((string) config('app.url'), '/');
            if ($base === '') {
                $base = 'https://ideatub.com';
            }

            return $base.$href;
        }

        return null;
    }

    private static function canonicalSegmentOrNull(string $candidate, array $canonicalByLower): ?string
    {
        $key = mb_strtolower($candidate);

        return $canonicalByLower[$key] ?? null;
    }

    private static function absoluteUrlForMicrositeRoot(Thought $root, ?string $shareToken): string
    {
        if ($shareToken !== null) {
            return route('shared-research.show', ['token' => $shareToken], true);
        }

        return route('idea.research.show', $root, true);
    }

    private static function absoluteUrlForMicrositePage(Thought $root, ?string $shareToken, string $pageSegment): string
    {
        if ($pageSegment === '' || $pageSegment === '0') {
            return self::absoluteUrlForMicrositeRoot($root, $shareToken);
        }
        if ($shareToken !== null) {
            return route('shared-research.page', [
                'token' => $shareToken,
                'page' => $pageSegment,
            ], true);
        }

        return route('idea.research.page', [
            'thought' => $root,
            'page' => $pageSegment,
        ], true);
    }

    private static function normalizeUuidForComparison(string $uuid): string
    {
        return strtolower(str_replace('-', '', trim($uuid)));
    }

    private static function hostIsIdeatubFrontend(string $host): bool
    {
        if (preg_match('/^(www\.)?ideatub\.com$/', $host) === 1) {
            return true;
        }
        $appUrl = config('app.url');
        if ($appUrl !== null && $appUrl !== '') {
            $appHost = parse_url((string) $appUrl, PHP_URL_HOST);
            if ($appHost !== null && $appHost !== '' && strcasecmp($host, (string) $appHost) === 0) {
                return true;
            }
        }

        return false;
    }
}

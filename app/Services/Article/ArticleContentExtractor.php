<?php

namespace App\Services\Article;

use DOMDocument;
use DOMNode;
use DOMXPath;

class ArticleContentExtractor
{
    /**
     * @return array{
     *     title: string,
     *     author: ?string,
     *     published_date: ?string,
     *     copyright: ?string,
     *     body_text: string,
     *     links: list<array{url: string, anchor_text: string}>,
     *     description: ?string
     * }
     */
    public function extract(string $html, string $sourceUrl): array
    {
        if (trim($html) === '') {
            return [
                'title' => parse_url($sourceUrl, PHP_URL_PATH) ?: '',
                'author' => null,
                'published_date' => null,
                'copyright' => null,
                'body_text' => '',
                'links' => [],
                'description' => null,
            ];
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        $title = $this->extractTitle($xpath);
        $author = $this->extractAuthor($xpath);
        $publishedDate = $this->extractPublishedDate($xpath);
        $copyright = $this->extractCopyright($xpath, $html);
        $bodyNode = $this->findBodyContainer($xpath);
        $bodyText = $bodyNode !== null ? $this->nodeToCleanText($bodyNode) : '';
        $links = $bodyNode !== null ? $this->extractLinks($xpath, $bodyNode, $sourceUrl) : [];
        $description = $this->extractDescription($xpath, $bodyText);

        return [
            'title' => $title,
            'author' => $author,
            'published_date' => $publishedDate,
            'copyright' => $copyright,
            'body_text' => $bodyText,
            'links' => $links,
            'description' => $description,
        ];
    }

    private function extractTitle(DOMXPath $xpath): string
    {
        $og = $xpath->query('//meta[@property="og:title"]/@content');
        if ($og->length > 0) {
            return trim($og->item(0)->nodeValue);
        }

        $title = $xpath->query('//title');
        if ($title->length > 0) {
            return trim($title->item(0)->textContent);
        }

        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) {
            return trim($h1->item(0)->textContent);
        }

        return '';
    }

    private function extractAuthor(DOMXPath $xpath): ?string
    {
        $meta = $xpath->query('//meta[@name="author"]/@content');
        if ($meta->length > 0 && trim($meta->item(0)->nodeValue) !== '') {
            return trim($meta->item(0)->nodeValue);
        }

        $jsonLd = $xpath->query('//script[@type="application/ld+json"]');
        for ($i = 0; $i < $jsonLd->length; $i++) {
            $data = json_decode(trim($jsonLd->item($i)->textContent), true);
            if (is_array($data)) {
                $author = $data['author']['name'] ?? $data['author'] ?? null;
                if (is_string($author) && $author !== '') {
                    return $author;
                }
            }
        }

        return null;
    }

    private function extractPublishedDate(DOMXPath $xpath): ?string
    {
        $meta = $xpath->query('//meta[@property="article:published_time"]/@content');
        if ($meta->length > 0 && trim($meta->item(0)->nodeValue) !== '') {
            return trim($meta->item(0)->nodeValue);
        }

        $jsonLd = $xpath->query('//script[@type="application/ld+json"]');
        for ($i = 0; $i < $jsonLd->length; $i++) {
            $data = json_decode(trim($jsonLd->item($i)->textContent), true);
            if (is_array($data) && isset($data['datePublished']) && is_string($data['datePublished'])) {
                return $data['datePublished'];
            }
        }

        $time = $xpath->query('//time/@datetime');
        if ($time->length > 0 && trim($time->item(0)->nodeValue) !== '') {
            return trim($time->item(0)->nodeValue);
        }

        return null;
    }

    private function extractCopyright(DOMXPath $xpath, string $html): ?string
    {
        $footer = $xpath->query('//footer');
        if ($footer->length > 0) {
            $footerText = trim($footer->item(0)->textContent);
            if (preg_match('/(?:©|copyright|all rights reserved).{0,200}/i', $footerText, $m)) {
                return trim($m[0]);
            }
        }

        if (preg_match('/(?:©|copyright)\s*.{0,200}/i', $html, $m)) {
            return trim(strip_tags($m[0]));
        }

        $meta = $xpath->query('//meta[@name="copyright"]/@content');
        if ($meta->length > 0 && trim($meta->item(0)->nodeValue) !== '') {
            return trim($meta->item(0)->nodeValue);
        }

        return null;
    }

    private function findBodyContainer(DOMXPath $xpath): ?DOMNode
    {
        $article = $xpath->query('//article');
        if ($article->length > 0) {
            return $article->item(0);
        }

        $main = $xpath->query('//main');
        if ($main->length > 0) {
            return $main->item(0);
        }

        $body = $xpath->query('//body');
        if ($body->length > 0) {
            return $body->item(0);
        }

        return null;
    }

    private function nodeToCleanText(DOMNode $node): string
    {
        $dom = $node->ownerDocument;
        $xpath = new DOMXPath($dom);

        foreach (['script', 'style', 'nav', 'noscript', 'iframe', 'svg'] as $tag) {
            $elements = $xpath->query('.//'.$tag, $node);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $el = $elements->item($i);
                $el->parentNode->removeChild($el);
            }
        }

        $text = $node->textContent;
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @return list<array{url: string, anchor_text: string}>
     */
    private function extractLinks(DOMXPath $xpath, DOMNode $container, string $sourceUrl): array
    {
        $anchors = $xpath->query('.//a[@href]', $container);
        $links = [];
        $seen = [];

        $baseSchemeHost = parse_url($sourceUrl, PHP_URL_SCHEME).'://'.parse_url($sourceUrl, PHP_URL_HOST);

        for ($i = 0; $i < $anchors->length; $i++) {
            $href = trim($anchors->item($i)->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $sourceUrl, $baseSchemeHost);
            if ($resolved === null || isset($seen[$resolved])) {
                continue;
            }

            $seen[$resolved] = true;
            $anchorText = trim($anchors->item($i)->textContent);

            $links[] = [
                'url' => $resolved,
                'anchor_text' => $anchorText,
            ];
        }

        return $links;
    }

    private function resolveUrl(string $href, string $sourceUrl, string $baseSchemeHost): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return parse_url($sourceUrl, PHP_URL_SCHEME).':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $baseSchemeHost.$href;
        }

        $basePath = dirname(parse_url($sourceUrl, PHP_URL_PATH) ?: '/');

        return $baseSchemeHost.rtrim($basePath, '/').'/'.$href;
    }

    private function extractDescription(DOMXPath $xpath, string $bodyText): ?string
    {
        $og = $xpath->query('//meta[@property="og:description"]/@content');
        if ($og->length > 0 && trim($og->item(0)->nodeValue) !== '') {
            return trim($og->item(0)->nodeValue);
        }

        $meta = $xpath->query('//meta[@name="description"]/@content');
        if ($meta->length > 0 && trim($meta->item(0)->nodeValue) !== '') {
            return trim($meta->item(0)->nodeValue);
        }

        if ($bodyText !== '') {
            return mb_substr($bodyText, 0, 300);
        }

        return null;
    }
}

# Article Capture Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture web articles into IdeaTub via MCP tool or web UI, extracting text/copyright/links, summarizing editorial links, and automatically running research.

**Architecture:** Staged pipeline of three async jobs (`ScrapeArticleContent` -> `ClassifyArticleLinks` -> existing `RunResearchRun`). All content flows through the existing `ThoughtCaptureService`. A dedicated `ArticleCaptureService` orchestrates entry, and `ArticleContentExtractor` isolates HTML parsing for testability. The frontend uses Blade + Alpine.js with the existing layout.

**Tech Stack:** Laravel 12 (PHP 8.2+), Pest tests, Blade + Alpine.js + Tailwind CSS 4, existing `LinkSummaryFetcher`/`ProcessThoughtLinkSummary`/`ResearchService` infrastructure.

**Spec:** `docs/superpowers/specs/2026-05-11-article-capture-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `app/Services/Article/ArticleContentExtractor.php` | Extract title, author, date, copyright, body, links from raw HTML |
| `app/Services/ArticleCaptureService.php` | Orchestrate capture: validate URL, create root thought, dispatch scrape job |
| `app/Jobs/ScrapeArticleContent.php` | Fetch HTML, extract content, create full-text child, dispatch link classification |
| `app/Jobs/ClassifyArticleLinks.php` | Filter editorial links, create ThoughtLinkSummary rows, dispatch summaries + research |
| `app/Http/Controllers/ArticleController.php` | Web routes for GET /articles and POST /articles |
| `app/Http/Controllers/Api/McpController.php` (modify) | Add `capture_article` tool definition and handler |
| `app/Support/ThoughtTypeNavigation.php` (modify) | Add `article` type definition |
| `resources/views/article/index.blade.php` | Article list page with URL input |
| `routes/web.php` (modify) | Add /articles routes |
| `tests/Feature/ArticleContentExtractorTest.php` | Unit tests for HTML extraction |
| `tests/Feature/ArticleCaptureServiceTest.php` | Feature tests for capture orchestration |
| `tests/Feature/ScrapeArticleContentJobTest.php` | Job tests with mocked HTTP |
| `tests/Feature/ClassifyArticleLinksJobTest.php` | Job tests for link filtering and dispatch |
| `tests/Feature/McpCaptureArticleTest.php` | MCP tool integration tests |
| `tests/Feature/ArticleWebTest.php` | Web route tests |
| `tests/fixtures/articles/blog-post.html` | Fixture: standard blog post HTML |
| `tests/fixtures/articles/minimal-page.html` | Fixture: minimal page with few metadata tags |

---

### Task 1: ArticleContentExtractor — Tests and Implementation

**Files:**
- Create: `tests/fixtures/articles/blog-post.html`
- Create: `tests/fixtures/articles/minimal-page.html`
- Create: `tests/Feature/ArticleContentExtractorTest.php`
- Create: `app/Services/Article/ArticleContentExtractor.php`

- [ ] **Step 1: Create blog post HTML fixture**

Create `tests/fixtures/articles/blog-post.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Test Article Title</title>
    <meta property="og:title" content="OG Test Article Title">
    <meta name="author" content="Jane Doe">
    <meta property="article:published_time" content="2026-05-01T10:00:00Z">
    <meta property="og:description" content="A test article for extraction.">
</head>
<body>
    <nav><a href="/about">About</a><a href="/contact">Contact</a></nav>
    <article>
        <h1>Test Article Title</h1>
        <p>This is the first paragraph of the article with enough words to be meaningful content for extraction testing purposes.</p>
        <p>Here is a <a href="https://example.com/referenced-article">referenced article</a> that should be extracted as an editorial link.</p>
        <p>And another link to <a href="https://other-site.com/research">important research</a> within the body.</p>
        <p><a href="https://twitter.com/intent/tweet?text=share">Tweet this</a></p>
        <p><a href="/about">About the author</a></p>
    </article>
    <footer>
        <p>&copy; 2026 Jane Doe. All Rights Reserved.</p>
        <a href="/privacy">Privacy</a>
    </footer>
    <script>console.log('should be stripped');</script>
</body>
</html>
```

- [ ] **Step 2: Create minimal page HTML fixture**

Create `tests/fixtures/articles/minimal-page.html`:

```html
<!DOCTYPE html>
<html>
<head><title>Minimal Page</title></head>
<body>
    <main>
        <p>Just some content without much metadata.</p>
        <p>A <a href="https://example.com/link">link</a> here.</p>
    </main>
</body>
</html>
```

- [ ] **Step 3: Write the failing tests**

Create `tests/Feature/ArticleContentExtractorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Article\ArticleContentExtractor;
use Tests\TestCase;

class ArticleContentExtractorTest extends TestCase
{
    private ArticleContentExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ArticleContentExtractor;
    }

    private function blogPostHtml(): string
    {
        return file_get_contents(base_path('tests/fixtures/articles/blog-post.html'));
    }

    private function minimalHtml(): string
    {
        return file_get_contents(base_path('tests/fixtures/articles/minimal-page.html'));
    }

    public function test_extracts_title_from_og_tag(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertSame('OG Test Article Title', $result['title']);
    }

    public function test_falls_back_to_title_tag(): void
    {
        $result = $this->extractor->extract($this->minimalHtml(), 'https://example.com/page');
        $this->assertSame('Minimal Page', $result['title']);
    }

    public function test_extracts_author(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertSame('Jane Doe', $result['author']);
    }

    public function test_author_null_when_missing(): void
    {
        $result = $this->extractor->extract($this->minimalHtml(), 'https://example.com/page');
        $this->assertNull($result['author']);
    }

    public function test_extracts_published_date(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertSame('2026-05-01T10:00:00Z', $result['published_date']);
    }

    public function test_extracts_copyright(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertStringContainsString('2026 Jane Doe', $result['copyright']);
    }

    public function test_copyright_null_when_missing(): void
    {
        $result = $this->extractor->extract($this->minimalHtml(), 'https://example.com/page');
        $this->assertNull($result['copyright']);
    }

    public function test_extracts_body_text_from_article_tag(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertStringContainsString('first paragraph', $result['body_text']);
        $this->assertStringNotContainsString('console.log', $result['body_text']);
        $this->assertStringNotContainsString('Privacy', $result['body_text']);
    }

    public function test_extracts_body_text_from_main_tag(): void
    {
        $result = $this->extractor->extract($this->minimalHtml(), 'https://example.com/page');
        $this->assertStringContainsString('Just some content', $result['body_text']);
    }

    public function test_extracts_links_within_article(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $urls = array_column($result['links'], 'url');
        $this->assertContains('https://example.com/referenced-article', $urls);
        $this->assertContains('https://other-site.com/research', $urls);
    }

    public function test_links_include_anchor_text(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $refLink = collect($result['links'])->firstWhere('url', 'https://example.com/referenced-article');
        $this->assertSame('referenced article', $refLink['anchor_text']);
    }

    public function test_extracts_description(): void
    {
        $result = $this->extractor->extract($this->blogPostHtml(), 'https://example.com/article');
        $this->assertSame('A test article for extraction.', $result['description']);
    }

    public function test_description_falls_back_to_body_truncation(): void
    {
        $result = $this->extractor->extract($this->minimalHtml(), 'https://example.com/page');
        $this->assertNotEmpty($result['description']);
        $this->assertLessThanOrEqual(300, mb_strlen($result['description']));
    }

    public function test_resolves_relative_urls(): void
    {
        $html = '<html><body><article><p><a href="/relative/path">link</a></p></article></body></html>';
        $result = $this->extractor->extract($html, 'https://example.com/article');
        $urls = array_column($result['links'], 'url');
        $this->assertContains('https://example.com/relative/path', $urls);
    }

    public function test_empty_html_returns_defaults(): void
    {
        $result = $this->extractor->extract('', 'https://example.com/page');
        $this->assertSame('', $result['body_text']);
        $this->assertSame([], $result['links']);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ArticleContentExtractorTest.php`
Expected: FAIL — class `ArticleContentExtractor` does not exist.

- [ ] **Step 5: Implement ArticleContentExtractor**

Create `app/Services/Article/ArticleContentExtractor.php`:

```php
<?php

namespace App\Services\Article;

use DOMDocument;
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
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
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

    private function findBodyContainer(DOMXPath $xpath): ?\DOMNode
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

    private function nodeToCleanText(\DOMNode $node): string
    {
        $dom = $node->ownerDocument;
        $xpath = new DOMXPath($dom);

        foreach (['script', 'style', 'nav', 'noscript', 'iframe', 'svg'] as $tag) {
            $elements = $xpath->query('.//' . $tag, $node);
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
    private function extractLinks(DOMXPath $xpath, \DOMNode $container, string $sourceUrl): array
    {
        $anchors = $xpath->query('.//a[@href]', $container);
        $links = [];
        $seen = [];

        $baseSchemeHost = parse_url($sourceUrl, PHP_URL_SCHEME) . '://' . parse_url($sourceUrl, PHP_URL_HOST);

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
            return parse_url($sourceUrl, PHP_URL_SCHEME) . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $baseSchemeHost . $href;
        }

        $basePath = dirname(parse_url($sourceUrl, PHP_URL_PATH) ?: '/');

        return $baseSchemeHost . rtrim($basePath, '/') . '/' . $href;
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
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ArticleContentExtractorTest.php`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Article/ArticleContentExtractor.php tests/Feature/ArticleContentExtractorTest.php tests/fixtures/articles/
git commit -m "feat: add ArticleContentExtractor with tests"
```

---

### Task 2: ArticleCaptureService — Tests and Implementation

**Files:**
- Create: `tests/Feature/ArticleCaptureServiceTest.php`
- Create: `app/Services/ArticleCaptureService.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ArticleCaptureServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\ArticleCaptureService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mockOpenRouter(): void
    {
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });
    }

    public function test_capture_creates_root_thought_and_dispatches_scrape_job(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $thought = $service->capture('https://example.com/test-article', [
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(Thought::class, $thought);
        $this->assertSame('article', $thought->source);
        $this->assertSame('https://example.com/test-article', $thought->source_metadata['url']);
        $this->assertSame('queued', $thought->source_metadata['status']);
        $this->assertContains('article', $thought->metadata['tags'] ?? []);

        Queue::assertPushed(ScrapeArticleContent::class, function ($job) use ($thought) {
            return $job->thoughtId === $thought->id;
        });
    }

    public function test_capture_rejects_duplicate_url_for_same_user(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $first = $service->capture('https://example.com/test-article', ['user_id' => $user->id]);
        $second = $service->capture('https://example.com/test-article', ['user_id' => $user->id]);

        $this->assertSame($first->id, $second->id);

        Queue::assertPushed(ScrapeArticleContent::class, 1);
    }

    public function test_capture_rejects_private_ip(): void
    {
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->capture('http://192.168.1.1/article', ['user_id' => $user->id]);
    }

    public function test_capture_rejects_non_http_scheme(): void
    {
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->capture('ftp://example.com/file', ['user_id' => $user->id]);
    }

    public function test_capture_applies_user_tags(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $thought = $service->capture('https://example.com/tagged', [
            'user_id' => $user->id,
            'tags' => ['ai', 'coding'],
        ]);

        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('article', $tags);
        $this->assertContains('ai', $tags);
        $this->assertContains('coding', $tags);
    }

    public function test_url_normalization_strips_tracking_params(): void
    {
        Queue::fake();
        $this->mockOpenRouter();

        $user = User::factory()->create();
        $service = app(ArticleCaptureService::class);

        $first = $service->capture('https://example.com/article?utm_source=twitter', ['user_id' => $user->id]);
        $second = $service->capture('https://example.com/article', ['user_id' => $user->id]);

        $this->assertSame($first->id, $second->id);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ArticleCaptureServiceTest.php`
Expected: FAIL — class `ArticleCaptureService` does not exist.

- [ ] **Step 3: Implement ArticleCaptureService**

Create `app/Services/ArticleCaptureService.php`:

```php
<?php

namespace App\Services;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use Illuminate\Support\Facades\DB;

class ArticleCaptureService
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref',
    ];

    public function __construct(
        private ThoughtCaptureService $captureService,
    ) {}

    /**
     * @param  array{user_id?: int, title?: string, tags?: list<string>, project?: string}  $options
     */
    public function capture(string $url, array $options = []): Thought
    {
        $url = trim($url);
        $this->validateUrl($url);

        $normalized = $this->normalizeUrl($url);
        $urlHash = hash('sha256', $normalized);

        $userId = (int) ($options['user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('user_id is required.');
        }

        $existing = Thought::query()
            ->where('user_id', $userId)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->whereJsonContains('source_metadata->url_hash', $urlHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $domain = parse_url($url, PHP_URL_HOST) ?: '';
        $userTags = $options['tags'] ?? [];
        $extraTags = array_merge(['article'], array_filter($userTags));

        $result = $this->captureService->create([
            'content' => "Capturing article: {$url}",
            'user_id' => $userId,
            'source' => 'article',
            'source_metadata' => [
                'url' => $url,
                'normalized_url' => $normalized,
                'url_hash' => $urlHash,
                'domain' => $domain,
                'status' => 'queued',
                'captured_at' => now()->toIso8601String(),
            ],
            'extra_tags' => $extraTags,
            'no_chunking' => true,
            'skip_ai_metadata' => false,
        ]);

        $thought = $result['thought'] ?? $result['root'];

        if (isset($options['title']) && $options['title'] !== '') {
            $sm = $thought->source_metadata ?? [];
            $sm['title_override'] = $options['title'];
            $thought->update(['source_metadata' => $sm]);
        }

        $thoughtId = $thought->id;
        DB::afterCommit(function () use ($thoughtId): void {
            ScrapeArticleContent::dispatch($thoughtId);
        });

        return $thought;
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP(S) URLs are supported.');
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new \InvalidArgumentException('URL must have a valid host.');
        }

        if ($this->isPrivateHost($host)) {
            throw new \InvalidArgumentException('Private or localhost URLs are not allowed.');
        }
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return true;
        }

        $ip = @inet_pton($host);
        if ($ip === false) {
            $resolved = @gethostbyname($host);
            if ($resolved === $host) {
                return false;
            }
            $ip = @inet_pton($resolved);
        }

        if ($ip === false) {
            return false;
        }

        $long = ip2long(inet_ntop($ip));
        if ($long === false) {
            return false;
        }

        return filter_var(inet_ntop($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host'] ?? '');
        $path = rtrim($parsed['path'] ?? '/', '/');
        if ($path === '') {
            $path = '/';
        }

        $query = '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $params);
            foreach (self::TRACKING_PARAMS as $param) {
                unset($params[$param]);
            }
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ArticleCaptureServiceTest.php`
Expected: All tests PASS (the `ScrapeArticleContent` job class doesn't need to exist yet for `Queue::fake()` to work, but create a stub if needed).

- [ ] **Step 5: Create ScrapeArticleContent job stub (if tests need it to exist)**

If tests fail because the class doesn't exist for dispatch, create a minimal stub at `app/Jobs/ScrapeArticleContent.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScrapeArticleContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $thoughtId,
    ) {}

    public function handle(): void {}
}
```

- [ ] **Step 6: Run tests again to verify**

Run: `php artisan test tests/Feature/ArticleCaptureServiceTest.php`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ArticleCaptureService.php app/Jobs/ScrapeArticleContent.php tests/Feature/ArticleCaptureServiceTest.php
git commit -m "feat: add ArticleCaptureService with URL validation, dedup, and tests"
```

---

### Task 3: ScrapeArticleContent Job — Tests and Implementation

**Files:**
- Create: `tests/Feature/ScrapeArticleContentJobTest.php`
- Modify: `app/Jobs/ScrapeArticleContent.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ScrapeArticleContentJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScrapeArticleContentJobTest extends TestCase
{
    use RefreshDatabase;

    private function mockOpenRouter(): void
    {
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });
    }

    private function createRootArticleThought(User $user, string $url = 'https://example.com/article'): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'content' => "Capturing article: {$url}",
            'source_metadata' => [
                'url' => $url,
                'domain' => 'example.com',
                'status' => 'queued',
                'url_hash' => hash('sha256', $url),
            ],
        ]);
    }

    public function test_scrape_creates_full_text_child_and_dispatches_classify(): void
    {
        Queue::fake([ClassifyArticleLinks::class]);
        $this->mockOpenRouter();

        $html = file_get_contents(base_path('tests/fixtures/articles/blog-post.html'));
        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('scraped', $root->source_metadata['status']);
        $this->assertStringContainsString('OG Test Article Title', $root->content);

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->where('source', 'article')
            ->first();

        $this->assertNotNull($child);
        $this->assertStringContainsString('first paragraph', $child->content);
        $this->assertStringContainsString('2026 Jane Doe', $child->content);
        $this->assertSame('full_text', $child->source_metadata['child_type']);

        Queue::assertPushed(ClassifyArticleLinks::class);
    }

    public function test_scrape_sets_failed_status_on_http_error(): void
    {
        Queue::fake();
        Http::fake(['example.com/*' => Http::response('Not Found', 404)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable) {
        }

        $root->refresh();
        $this->assertSame('scrape_failed', $root->source_metadata['status']);
    }

    public function test_scrape_sets_failed_status_on_empty_content(): void
    {
        Queue::fake();
        $this->mockOpenRouter();
        Http::fake(['example.com/*' => Http::response('<html><body></body></html>', 200)]);

        $user = User::factory()->create();
        $root = $this->createRootArticleThought($user);

        $job = new ScrapeArticleContent($root->id);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('scrape_failed', $root->source_metadata['status']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ScrapeArticleContentJobTest.php`
Expected: FAIL — job `handle()` is empty stub.

- [ ] **Step 3: Implement ScrapeArticleContent job**

Replace the stub in `app/Jobs/ScrapeArticleContent.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\Article\ArticleContentExtractor;
use App\Services\ThoughtCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeArticleContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(
        public readonly string $thoughtId,
    ) {}

    public function handle(
        ArticleContentExtractor $extractor,
        ThoughtCaptureService $captureService,
    ): void {
        $root = Thought::query()->find($this->thoughtId);
        if ($root === null) {
            return;
        }

        $this->updateStatus($root, 'scraping');

        $url = $root->source_metadata['url'] ?? '';
        if ($url === '') {
            $this->markFailed($root, 'No URL in source_metadata');
            return;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; IdeaTubArticle/1.0)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])
                ->timeout(30)
                ->connectTimeout(10)
                ->get($url);

            if ($response->failed()) {
                $this->markFailed($root, "HTTP {$response->status()}");
                return;
            }

            $html = $response->body();
        } catch (\Throwable $e) {
            $this->markFailed($root, $e->getMessage());
            throw $e;
        }

        $extracted = $extractor->extract($html, $url);

        if (trim($extracted['body_text']) === '') {
            $this->markFailed($root, 'No content extracted');
            return;
        }

        $contentWithCopyright = $extracted['body_text'];
        if ($extracted['copyright'] !== null) {
            $contentWithCopyright .= "\n\n---\n© " . $extracted['copyright'] . "\nSource: {$url}";
        }

        $captureService->create([
            'content' => $contentWithCopyright,
            'user_id' => (int) $root->user_id,
            'parent_id' => $root->id,
            'source' => 'article',
            'source_metadata' => [
                'child_type' => 'full_text',
                'url' => $url,
                'title' => $extracted['title'],
                'author' => $extracted['author'],
                'published_date' => $extracted['published_date'],
                'copyright' => $extracted['copyright'],
                'domain' => $root->source_metadata['domain'] ?? '',
            ],
            'no_chunking' => false,
            'skip_ai_metadata' => false,
        ]);

        $title = $root->source_metadata['title_override'] ?? $extracted['title'] ?: $url;
        $byLine = collect([$extracted['author'], $extracted['published_date']])->filter()->implode(' | ');
        $rootContent = $title . "\n\n" . $url;
        if ($byLine !== '') {
            $rootContent .= "\nBy " . $byLine;
        }

        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'scraped';
        $sm['title'] = $extracted['title'];
        $sm['author'] = $extracted['author'];
        $sm['published_date'] = $extracted['published_date'];
        $sm['copyright'] = $extracted['copyright'];
        $sm['description'] = $extracted['description'];
        $sm['link_count'] = count($extracted['links']);

        $root->update([
            'content' => $rootContent,
            'source_metadata' => $sm,
        ]);

        $links = $extracted['links'];
        DB::afterCommit(function () use ($links) {
            ClassifyArticleLinks::dispatch($this->thoughtId, $links);
        });
    }

    private function updateStatus(Thought $root, string $status): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = $status;
        $root->update(['source_metadata' => $sm]);
    }

    private function markFailed(Thought $root, string $error): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'scrape_failed';
        $sm['error'] = mb_substr($error, 0, 255);
        $root->update(['source_metadata' => $sm]);
    }
}
```

- [ ] **Step 4: Create ClassifyArticleLinks job stub**

Create `app/Jobs/ClassifyArticleLinks.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClassifyArticleLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{url: string, anchor_text: string}>  $links
     */
    public function __construct(
        public readonly string $thoughtId,
        public readonly array $links,
    ) {}

    public function handle(): void {}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ScrapeArticleContentJobTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ScrapeArticleContent.php app/Jobs/ClassifyArticleLinks.php tests/Feature/ScrapeArticleContentJobTest.php
git commit -m "feat: implement ScrapeArticleContent job with tests"
```

---

### Task 4: ClassifyArticleLinks Job — Tests and Implementation

**Files:**
- Create: `tests/Feature/ClassifyArticleLinksJobTest.php`
- Modify: `app/Jobs/ClassifyArticleLinks.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ClassifyArticleLinksJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ProcessThoughtLinkSummary;
use App\Jobs\RunResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassifyArticleLinksJobTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithResearchSkill(): User
    {
        $user = User::factory()->create();

        $skill = ResearchSkill::query()->create([
            'user_id' => $user->id,
            'name' => 'Default',
            'is_active' => true,
            'is_default' => true,
            'is_manual_enabled' => true,
        ]);

        ResearchSkillVersion::query()->create([
            'research_skill_id' => $skill->id,
            'workflow_type' => 'quick_brief',
            'instructions' => 'Research this.',
            'context_options' => [],
            'output_shape' => [],
            'intensity' => 'medium',
        ]);

        return $user;
    }

    private function createRootArticleThought(User $user): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'content' => 'Test Article',
            'source_metadata' => [
                'url' => 'https://example.com/article',
                'domain' => 'example.com',
                'status' => 'scraped',
            ],
        ]);
    }

    public function test_filters_out_social_and_nav_links(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $links = [
            ['url' => 'https://other-site.com/research', 'anchor_text' => 'Research'],
            ['url' => 'https://twitter.com/intent/tweet?text=hi', 'anchor_text' => 'Tweet'],
            ['url' => 'https://facebook.com/sharer/sharer.php', 'anchor_text' => 'Share'],
            ['url' => 'https://example.com/about', 'anchor_text' => 'About'],
            ['url' => 'https://example.com/article', 'anchor_text' => 'Self'],
            ['url' => 'https://example.com/image.jpg', 'anchor_text' => 'Image'],
        ];

        $job = new ClassifyArticleLinks($root->id, $links);
        app()->call([$job, 'handle']);

        $summaries = ThoughtLinkSummary::query()
            ->where('source_thought_id', $root->id)
            ->get();

        $this->assertCount(1, $summaries);
        $this->assertSame('https://other-site.com/research', $summaries->first()->original_url);

        Queue::assertPushed(ProcessThoughtLinkSummary::class, 1);
    }

    public function test_dispatches_research_run(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, [
            ['url' => 'https://other-site.com/article', 'anchor_text' => 'Link'],
        ]);
        app()->call([$job, 'handle']);

        Queue::assertPushed(RunResearchRun::class);
    }

    public function test_updates_root_status_to_complete(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, []);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('complete', $root->source_metadata['status']);
    }

    public function test_handles_empty_links_gracefully(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, []);
        app()->call([$job, 'handle']);

        $this->assertSame(0, ThoughtLinkSummary::query()->count());
        $root->refresh();
        $this->assertSame('complete', $root->source_metadata['status']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ClassifyArticleLinksJobTest.php`
Expected: FAIL — job `handle()` is empty.

- [ ] **Step 3: Implement ClassifyArticleLinks job**

Replace the stub in `app/Jobs/ClassifyArticleLinks.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Services\ResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClassifyArticleLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    private const SOCIAL_DOMAINS = [
        'twitter.com', 'x.com', 'facebook.com', 'linkedin.com',
        'reddit.com', 'pinterest.com', 'instagram.com', 'tiktok.com',
        'threads.net', 'mastodon.social',
    ];

    private const SOCIAL_PATH_PREFIXES = [
        '/intent/', '/sharer/', '/share', '/shareArticle',
    ];

    private const NOISE_PATHS = [
        '/about', '/contact', '/privacy', '/terms', '/login',
        '/register', '/signup', '/subscribe', '/feed', '/rss',
    ];

    private const MEDIA_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'mp4',
        'mp3', 'wav', 'pdf', 'zip', 'tar', 'gz',
    ];

    /**
     * @param  list<array{url: string, anchor_text: string}>  $links
     */
    public function __construct(
        public readonly string $thoughtId,
        public readonly array $links,
    ) {}

    public function handle(ResearchService $researchService): void
    {
        $root = Thought::query()->find($this->thoughtId);
        if ($root === null) {
            return;
        }

        $this->updateStatus($root, 'links_processing');

        $articleUrl = $root->source_metadata['url'] ?? '';
        $articleDomain = $root->source_metadata['domain'] ?? '';

        $editorialLinks = array_filter($this->links, function (array $link) use ($articleUrl, $articleDomain): bool {
            return $this->isEditorial($link['url'], $articleUrl, $articleDomain);
        });

        $editorialCount = 0;
        foreach ($editorialLinks as $link) {
            $normalized = strtolower($link['url']);
            $hash = hash('sha256', $normalized);

            $existing = ThoughtLinkSummary::query()
                ->where('source_thought_id', $root->id)
                ->where('normalized_url_hash', $hash)
                ->first();

            if ($existing !== null) {
                continue;
            }

            $row = ThoughtLinkSummary::query()->create([
                'user_id' => (int) $root->user_id,
                'source_thought_id' => $root->id,
                'source_type' => 'article',
                'original_url' => $link['url'],
                'normalized_url' => $normalized,
                'normalized_url_hash' => $hash,
                'classification' => 'article_reference',
                'processing_status' => 'queued',
                'source_excerpt' => mb_substr($link['anchor_text'], 0, 500),
            ]);

            ProcessThoughtLinkSummary::dispatch($row->id);
            $editorialCount++;
        }

        try {
            $researchService->queueResearchRunForIdea($root, 'article');
        } catch (\Throwable $e) {
            Log::warning('Article research queue failed', [
                'thought_id' => $root->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sm = $root->source_metadata ?? [];
        $sm['status'] = 'complete';
        $sm['editorial_link_count'] = $editorialCount;
        $root->update(['source_metadata' => $sm]);
    }

    public function failed(\Throwable $exception): void
    {
        $root = Thought::query()->find($this->thoughtId);
        if ($root !== null) {
            $sm = $root->source_metadata ?? [];
            $sm['status'] = 'links_failed';
            $sm['error'] = mb_substr($exception->getMessage(), 0, 255);
            $root->update(['source_metadata' => $sm]);
        }
    }

    private function updateStatus(Thought $root, string $status): void
    {
        $sm = $root->source_metadata ?? [];
        $sm['status'] = $status;
        $root->update(['source_metadata' => $sm]);
    }

    private function isEditorial(string $url, string $articleUrl, string $articleDomain): bool
    {
        if (strcasecmp($url, $articleUrl) === 0) {
            return false;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = strtolower($parsed['path'] ?? '');

        foreach (self::SOCIAL_DOMAINS as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                foreach (self::SOCIAL_PATH_PREFIXES as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }
                return false;
            }
        }

        if ($host === $articleDomain || str_ends_with($host, '.' . $articleDomain)) {
            foreach (self::NOISE_PATHS as $noise) {
                if ($path === $noise || str_starts_with($path, $noise . '/')) {
                    return false;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ClassifyArticleLinksJobTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ClassifyArticleLinks.php tests/Feature/ClassifyArticleLinksJobTest.php
git commit -m "feat: implement ClassifyArticleLinks job with editorial link filtering"
```

---

### Task 5: MCP `capture_article` Tool — Tests and Implementation

**Files:**
- Create: `tests/Feature/McpCaptureArticleTest.php`
- Modify: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/McpCaptureArticleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpCaptureArticleTest extends TestCase
{
    use RefreshDatabase;

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_' . str_repeat('a', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    public function test_capture_article_creates_thought_and_queues_scrape(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();

        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->once()->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_article',
            'params' => ['url' => 'https://example.com/my-article'],
        ]);

        $response->assertStatus(200);
        $id = $response->json('result.id');
        $this->assertIsString($id);
        $response->assertJsonPath('result.status', 'queued');

        $thought = Thought::query()->whereKey($id)->first();
        $this->assertNotNull($thought);
        $this->assertSame('article', $thought->source);

        Queue::assertPushed(ScrapeArticleContent::class);
    }

    public function test_capture_article_requires_url(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_article',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
    }

    public function test_capture_article_appears_in_tools_list(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(200);
        $tools = $response->json('result.tools');
        $names = array_column($tools, 'name');
        $this->assertContains('capture_article', $names);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/McpCaptureArticleTest.php`
Expected: FAIL — `capture_article` not in tools list, unknown method.

- [ ] **Step 3: Add `capture_article` to McpController**

In `app/Http/Controllers/Api/McpController.php`, make three changes:

**3a.** Add to `mcpMethodNames()` array (around line 117, after `'capture_video'`):

```php
'capture_article',
```

**3b.** Add tool definition to `respondToolsList()` (after the `capture_video` definition):

```php
[
    'name' => 'capture_article',
    'description' => 'Capture a web article into IdeaTub. Scrapes the article content, extracts copyright and editorial links, summarizes each link, and runs research automatically.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'The article URL to capture'],
            'title' => ['type' => 'string', 'description' => 'Optional title override'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Additional tags'],
            'project' => ['type' => 'string', 'description' => 'Project context'],
        ],
        'required' => ['url'],
    ],
],
```

**3c.** Add to `dispatch()` match (around line 792, after `'capture_video'`):

```php
'capture_article' => $this->captureArticle($params),
```

**3d.** Add the handler method (after `captureVideo()`):

```php
/**
 * capture_article: Capture a web article via {@see ArticleCaptureService}.
 *
 * @param  array<string, mixed>  $params
 * @return array{id: string, status: string, url: string}
 */
private function captureArticle(array $params): array
{
    $v = Validator::make($params, [
        'url' => 'required|string|max:2048',
        'title' => 'sometimes|nullable|string|max:512',
        'tags' => 'sometimes|nullable|array',
        'tags.*' => 'string|max:128',
        'project' => 'sometimes|nullable|string|max:256',
    ]);
    if ($v->fails()) {
        throw new \InvalidArgumentException($v->errors()->first());
    }

    $user = Auth::user();
    if ($user === null) {
        throw new \InvalidArgumentException('Not authenticated.');
    }

    $service = app(\App\Services\ArticleCaptureService::class);

    $thought = $service->capture(trim((string) $params['url']), [
        'user_id' => $user->id,
        'title' => $params['title'] ?? null,
        'tags' => $params['tags'] ?? [],
        'project' => $params['project'] ?? null,
    ]);

    return [
        'id' => $thought->id,
        'status' => $thought->source_metadata['status'] ?? 'queued',
        'url' => $thought->source_metadata['url'] ?? '',
    ];
}
```

**3e.** Add the use statement at the top of the file if not already present — `ArticleCaptureService` is resolved via `app()` in the handler, so no use statement is needed.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/McpCaptureArticleTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpCaptureArticleTest.php
git commit -m "feat: add capture_article MCP tool"
```

---

### Task 6: ThoughtTypeNavigation — Add Article Type

**Files:**
- Modify: `app/Support/ThoughtTypeNavigation.php`
- Modify: `tests/Unit/Support/ThoughtTypeNavigationTest.php` (if it exists and covers type definitions)

- [ ] **Step 1: Add article type definition**

In `app/Support/ThoughtTypeNavigation.php`, add to `TYPE_DEFINITIONS` constant after the `'meeting'` entry:

```php
'article' => [
    'collection_label' => 'Articles',
    'thought_label' => 'Article',
    'route_name' => 'idea.stream.articles',
    'stored_values' => ['article', 'articles'],
],
```

- [ ] **Step 2: Update resolveThoughtToTypeKey for article source**

In the `resolveThoughtToTypeKey()` method, add after the email check (around line 142):

```php
if ($sourceKey === 'article') {
    return 'article';
}
```

- [ ] **Step 3: Run existing navigation tests**

Run: `php artisan test tests/Unit/Support/ThoughtTypeNavigationTest.php`
Expected: PASS (existing tests should still pass; new type doesn't break them).

- [ ] **Step 4: Commit**

```bash
git add app/Support/ThoughtTypeNavigation.php
git commit -m "feat: add article type to ThoughtTypeNavigation"
```

---

### Task 7: Web Routes and Controller — Tests and Implementation

**Files:**
- Create: `tests/Feature/ArticleWebTest.php`
- Create: `app/Http/Controllers/ArticleController.php`
- Modify: `routes/web.php`
- Create: `resources/views/article/index.blade.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ArticleWebTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_articles_index_requires_auth(): void
    {
        $this->get('/articles')->assertRedirect('/login');
    }

    public function test_articles_index_shows_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/articles')->assertStatus(200);
    }

    public function test_articles_index_lists_captured_articles(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'parent_id' => null,
            'content' => 'Test Article Title',
            'source_metadata' => [
                'url' => 'https://example.com/article',
                'domain' => 'example.com',
                'status' => 'complete',
                'title' => 'Test Article Title',
            ],
        ]);

        $response = $this->actingAs($user)->get('/articles');
        $response->assertSee('Test Article Title');
        $response->assertSee('example.com');
    }

    public function test_store_captures_article_and_redirects(): void
    {
        Queue::fake();

        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'https://example.com/new-article',
        ]);

        $response->assertRedirect('/articles');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('thoughts', [
            'user_id' => $user->id,
            'source' => 'article',
        ]);

        Queue::assertPushed(ScrapeArticleContent::class);
    }

    public function test_store_validates_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('url');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ArticleWebTest.php`
Expected: FAIL — route `/articles` not defined.

- [ ] **Step 3: Create ArticleController**

Create `app/Http/Controllers/ArticleController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Models\User;
use App\Services\ArticleCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $articles = Thought::query()
            ->where('user_id', $user->id)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('article.index', compact('articles'));
    }

    public function store(Request $request, ArticleCaptureService $captureService): RedirectResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2048',
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $captureService->capture($validated['url'], [
                'user_id' => $user->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('articles.index')
                ->withInput()
                ->withErrors(['url' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('articles.index')
                ->withInput()
                ->with('error', 'Unable to capture article. Please try again.');
        }

        return redirect()
            ->route('articles.index')
            ->with('success', 'Article capture started.');
    }
}
```

- [ ] **Step 4: Add routes to web.php**

In `routes/web.php`, inside the `Route::middleware('auth')->group(...)` block, add after the `/videos` route (around line 199):

```php
Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
Route::post('/articles', [ArticleController::class, 'store'])->name('articles.store');
```

Add the use statement at the top of the file:

```php
use App\Http\Controllers\ArticleController;
```

- [ ] **Step 5: Add stream route for articles**

In `routes/web.php`, add after the stream/meetings route (around line 185):

```php
Route::get('/stream/articles', [IdeaController::class, 'streamArticles'])->name('idea.stream.articles');
```

Add the `streamArticles` method to `IdeaController` (follow the pattern of `streamEmails`/`streamResearch`/etc.):

```php
public function streamArticles(Request $request)
{
    return $this->streamByType($request, 'article');
}
```

Check `IdeaController` for the `streamByType` pattern — if it exists, use it. If each type has its own method with a query filter, follow that pattern instead.

- [ ] **Step 6: Create Blade view**

Create `resources/views/article/index.blade.php`:

```blade
@extends('layouts.idea')

@section('title', 'Articles — IdeaTub')

@section('content')
<div class="max-w-[700px] mx-auto px-6 pt-16 pb-24">

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">Articles</h1>

    {{-- Capture form --}}
    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Capture article</h2>
        <form method="POST" action="{{ route('articles.store') }}" class="flex gap-2">
            @csrf
            <input
                type="url"
                name="url"
                placeholder="Paste article URL..."
                required
                value="{{ old('url') }}"
                class="flex-1 rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
            >
            <button
                type="submit"
                class="inline-flex items-center rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium text-white hover:bg-memory-violet/90 transition-colors"
            >Capture</button>
        </form>
        @error('url')
            <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    {{-- Article list --}}
    @forelse ($articles as $article)
        @php
            $sm = $article->source_metadata ?? [];
            $status = $sm['status'] ?? 'unknown';
            $title = $sm['title'] ?? $article->content;
            $domain = $sm['domain'] ?? '';
            $url = $sm['url'] ?? '';
            $linkCount = $sm['editorial_link_count'] ?? $sm['link_count'] ?? 0;
            $statusColor = match ($status) {
                'complete' => 'bg-neural-teal/15 text-neural-teal',
                'queued', 'scraping', 'links_processing' => 'bg-amber-100 text-amber-700',
                'scraped' => 'bg-blue-100 text-blue-700',
                default => str_contains($status, 'failed') ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-500',
            };
        @endphp
        <div class="rounded-xl border border-memory-violet/10 bg-white/60 p-4 mb-3 hover:bg-white/80 transition-colors">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <a href="{{ route('thoughts.show', $article) }}" class="text-sm font-medium text-deep-indigo hover:text-memory-violet truncate block">
                        {{ Str::limit($title, 80) }}
                    </a>
                    <div class="flex items-center gap-2 mt-1 text-xs text-slate-brand/60">
                        @if ($domain)
                            <span>{{ $domain }}</span>
                            <span>&middot;</span>
                        @endif
                        <span>{{ $article->created_at->diffForHumans() }}</span>
                        @if ($linkCount > 0)
                            <span>&middot;</span>
                            <span>{{ $linkCount }} {{ Str::plural('link', $linkCount) }}</span>
                        @endif
                    </div>
                </div>
                <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $statusColor }}">
                    {{ str_replace('_', ' ', $status) }}
                </span>
            </div>
        </div>
    @empty
        <p class="text-center text-sm text-slate-brand/50 py-12">No articles captured yet. Paste a URL above to get started.</p>
    @endforelse

    <div class="mt-6">
        {{ $articles->links() }}
    </div>
</div>
@endsection
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ArticleWebTest.php`
Expected: All tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ArticleController.php resources/views/article/index.blade.php routes/web.php tests/Feature/ArticleWebTest.php
git commit -m "feat: add /articles web UI with capture form and article list"
```

---

### Task 8: IdeaController Stream Articles Route

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`

- [ ] **Step 1: Check IdeaController stream method pattern**

Read `app/Http/Controllers/IdeaController.php` and find how `streamEmails()`, `streamResearch()`, etc. are implemented. They likely call a shared method with a type filter.

- [ ] **Step 2: Add streamArticles method**

Read `IdeaController.php` and find how `streamEmails()`, `streamResearch()`, `streamPlans()`, and `streamMeetings()` are implemented. They each render the `idea.stream` view with a type filter and pass it to the Blade template. Add a `streamArticles()` method following the identical pattern used by those methods, substituting `'article'` as the type key. The method should:
- Accept a `Request`
- Query root-level thoughts with `source = 'article'` (or filter by the article type, matching how the others filter)
- Return the `idea.stream` view with the filtered collection and `$active = 'article'`

- [ ] **Step 3: Run all stream-related tests**

Run: `php artisan test tests/Feature/ThoughtTypePagesTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "feat: add streamArticles route to IdeaController"
```

---

### Task 9: Full Integration Test

**Files:**
- Create: `tests/Feature/ArticleCapturePipelineTest.php`

- [ ] **Step 1: Write integration test**

Create `tests/Feature/ArticleCapturePipelineTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ProcessThoughtLinkSummary;
use App\Jobs\ScrapeArticleContent;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleCapturePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function mockOpenRouter(): void
    {
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->andReturn($embed);
            $mock->shouldReceive('extractMetadata')->andReturn([
                'type' => 'article',
                'tags' => ['article'],
                'people' => [],
                'action_items' => [],
            ]);
        });
    }

    public function test_full_pipeline_scrape_then_classify(): void
    {
        Queue::fake([ProcessThoughtLinkSummary::class]);
        $this->mockOpenRouter();

        $html = file_get_contents(base_path('tests/fixtures/articles/blog-post.html'));
        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/articles', [
            'url' => 'https://example.com/full-pipeline-test',
        ]);

        $response->assertRedirect('/articles');

        $root = Thought::query()
            ->where('user_id', $user->id)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->first();

        $this->assertNotNull($root);
        $this->assertSame('queued', $root->source_metadata['status']);

        $scrapeJob = new ScrapeArticleContent($root->id);
        app()->call([$scrapeJob, 'handle']);

        $root->refresh();
        $this->assertSame('scraped', $root->source_metadata['status']);

        $child = Thought::query()
            ->where('parent_id', $root->id)
            ->first();
        $this->assertNotNull($child);
        $this->assertStringContainsString('first paragraph', $child->content);
    }
}
```

- [ ] **Step 2: Run integration test**

Run: `php artisan test tests/Feature/ArticleCapturePipelineTest.php`
Expected: PASS.

- [ ] **Step 3: Run entire test suite**

Run: `php artisan test`
Expected: All existing tests still pass. No regressions.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/ArticleCapturePipelineTest.php
git commit -m "test: add article capture pipeline integration test"
```

---

### Task 10: Update CLAUDE.md MCP Tool Documentation

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add capture_article documentation**

In `CLAUDE.md`, add a section documenting the new `capture_article` MCP tool. Follow the pattern of the existing `capture_video` documentation. Add after the meeting notes section:

```markdown
## IdeaTub: Capture web articles via capture_article

Use the MCP tool **capture_article** to scrape and save a web article to IdeaTub:

- **url** (required): The article URL to capture.
- **title**: Optional title override.
- **tags**: Optional extra tags.
- **project**: Optional project context.

The pipeline automatically: scrapes the article content, extracts copyright notices and editorial links, summarizes each editorial link, and runs research on the article. Progress is tracked via `source_metadata.status` on the root thought.

**Stream:** Articles appear in Stream with source `article`. Filter at `/stream/articles`.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add capture_article MCP tool documentation"
```

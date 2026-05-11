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

<?php

namespace Tests\Unit\Services;

use App\Services\ThoughtChunkingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThoughtChunkingServiceTest extends TestCase
{
    private ThoughtChunkingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThoughtChunkingService;
    }

    #[Test]
    public function word_count_returns_zero_for_empty_string(): void
    {
        $this->assertSame(0, $this->service->wordCount(''));
        $this->assertSame(0, $this->service->wordCount('   '));
    }

    #[Test]
    public function word_count_counts_whitespace_separated_words(): void
    {
        $this->assertSame(1, $this->service->wordCount('hello'));
        $this->assertSame(3, $this->service->wordCount('one two three'));
        $this->assertSame(3, $this->service->wordCount('  one   two   three  '));
    }

    #[Test]
    public function word_count_uses_unicode(): void
    {
        $this->assertSame(2, $this->service->wordCount('café naïve'));
    }

    #[Test]
    public function split_at_headings_returns_single_intro_section_when_no_headings(): void
    {
        $content = 'Some intro text without any markdown headings.';
        $sections = $this->service->splitAtHeadings($content);
        $this->assertCount(1, $sections);
        $this->assertSame('Intro', $sections[0]['title']);
        $this->assertSame($content, $sections[0]['content']);
    }

    #[Test]
    public function split_at_headings_splits_at_hash_headings(): void
    {
        $content = "Intro paragraph.\n\n## First section\n\nContent one.\n\n### Sub\n\nSub content.\n\n## Second\n\nContent two.";
        $sections = $this->service->splitAtHeadings($content);
        $this->assertCount(4, $sections);
        $this->assertSame('Intro', $sections[0]['title']);
        $this->assertSame('Intro paragraph.', $sections[0]['content']);
        $this->assertSame('First section', $sections[1]['title']);
        $this->assertStringContainsString('Content one.', $sections[1]['content']);
        $this->assertSame('Sub', $sections[2]['title']);
        $this->assertStringContainsString('Sub content.', $sections[2]['content']);
        $this->assertSame('Second', $sections[3]['title']);
        $this->assertStringContainsString('Content two.', $sections[3]['content']);
    }

    #[Test]
    public function split_at_headings_handles_empty_content(): void
    {
        $sections = $this->service->splitAtHeadings('');
        $this->assertCount(1, $sections);
        $this->assertSame('Intro', $sections[0]['title']);
        $this->assertSame('', $sections[0]['content']);
    }

    #[Test]
    public function split_at_headings_merges_empty_first_section_when_doc_starts_with_heading(): void
    {
        $content = "# Made by ON - Competitive Profile\n\n**Generated**: 2025-01-20\n\n---\n\n## Summary\n\nFirst paragraph of summary.";
        $sections = $this->service->splitAtHeadings($content);
        $this->assertGreaterThanOrEqual(2, count($sections));
        $this->assertNotEmpty(trim($sections[0]['content']), 'Root section must not be blank so the Stream card shows content.');
        $this->assertStringContainsString('Made by ON', $sections[0]['content']);
        $this->assertStringContainsString('Summary', $sections[1]['title']);
    }

    #[Test]
    public function should_chunk_returns_false_when_under_word_threshold(): void
    {
        $short = implode(' ', array_fill(0, 100, 'word'));
        $this->assertFalse($this->service->shouldChunk($short, []));
    }

    #[Test]
    public function should_chunk_returns_true_when_over_500_words_and_no_opt_out(): void
    {
        $long = implode(' ', array_fill(0, 501, 'word'));
        $this->assertTrue($this->service->shouldChunk($long, []));
    }

    #[Test]
    public function should_chunk_returns_false_when_no_chunking_in_options(): void
    {
        $long = implode(' ', array_fill(0, 501, 'word'));
        $this->assertFalse($this->service->shouldChunk($long, ['no_chunking' => true]));
        $this->assertFalse($this->service->shouldChunk($long, ['no-chunking' => true]));
    }

    #[Test]
    public function should_chunk_returns_false_when_no_chunking_in_source_metadata(): void
    {
        $long = implode(' ', array_fill(0, 501, 'word'));
        $this->assertFalse($this->service->shouldChunk($long, [
            'source_metadata' => ['no_chunking' => true],
        ]));
        $this->assertFalse($this->service->shouldChunk($long, [
            'source_metadata' => ['no-chunking' => true],
        ]));
    }

    #[Test]
    public function is_no_chunking_requested_returns_true_for_no_chunking_keys(): void
    {
        $this->assertTrue($this->service->isNoChunkingRequested(['no_chunking' => true]));
        $this->assertTrue($this->service->isNoChunkingRequested(['no-chunking' => true]));
        $this->assertTrue($this->service->isNoChunkingRequested([
            'source_metadata' => ['no_chunking' => true],
        ]));
        $this->assertTrue($this->service->isNoChunkingRequested([
            'source_metadata' => ['no-chunking' => true],
        ]));
    }

    #[Test]
    public function is_no_chunking_requested_returns_false_when_not_set(): void
    {
        $this->assertFalse($this->service->isNoChunkingRequested([]));
        $this->assertFalse($this->service->isNoChunkingRequested(['no_chunking' => false]));
        $this->assertFalse($this->service->isNoChunkingRequested(['source_metadata' => []]));
    }
}

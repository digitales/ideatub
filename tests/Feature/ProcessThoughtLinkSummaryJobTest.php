<?php

namespace Tests\Feature;

use App\Jobs\ProcessThoughtLinkSummary;
use App\Models\ThoughtLinkSummary;
use App\Services\LinkSummary\LinkSummaryFetcher;
use App\Services\LinkSummary\LinkSummaryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessThoughtLinkSummaryJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(int $summaryId, int $attempts = 1): ProcessThoughtLinkSummary
    {
        return new class($summaryId, $attempts) extends ProcessThoughtLinkSummary
        {
            public function __construct(int $thoughtLinkSummaryId, private int $attemptsOverride)
            {
                parent::__construct($thoughtLinkSummaryId);
            }

            public function attempts(): int
            {
                return $this->attemptsOverride;
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function job_has_bounded_retry_policy(): void
    {
        $summary = ThoughtLinkSummary::factory()->create();
        $job = new ProcessThoughtLinkSummary($summary->id);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff);
    }

    #[Test]
    public function job_summarizes_successfully_after_fetch_and_generation(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'source_excerpt' => 'Context from the newsletter.',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Example Article</title></head><body><p>'
                .str_repeat('Visible paragraph with enough characters for the thin-content threshold. ', 4)
                .'</p></body></html>',
                200
            ),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $title, string $text, string $excerpt) {
                return $title === 'Example Article'
                    && str_contains($text, 'thin-content threshold')
                    && $excerpt === 'Context from the newsletter.';
            })
            ->andReturn([
                'title' => 'Polished title',
                'summary_text' => 'Short summary.',
                'support_judgment' => 'supports',
                'why_it_matters' => 'Because testing.',
                'quality_notes' => null,
                'usefulness_score' => 80,
            ]);
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('summarized', $summary->processing_status);
        $this->assertSame(200, $summary->fetch_status_code);
        $this->assertSame('Polished title', $summary->resolved_title);
        $this->assertSame('Short summary.', $summary->summary_text);
        $this->assertSame('supports', $summary->support_judgment);
        $this->assertSame('Because testing.', $summary->why_it_matters);
        $this->assertNull($summary->quality_notes);
        $this->assertSame(80, $summary->usefulness_score);
        $this->assertNotNull($summary->content_fingerprint);
        $this->assertNotNull($summary->processed_at);
        $this->assertNull($summary->failure_stage);
        $this->assertNull($summary->failure_reason);
    }

    #[Test]
    public function job_marks_fetch_failure_and_does_not_call_generator_on_http_error(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response('Bad Gateway', 502),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = $this->makeJob($summary->id, attempts: 3);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('failed', $summary->processing_status);
        $this->assertSame('fetch', $summary->failure_stage);
        $this->assertNotNull($summary->failure_reason);
        $this->assertSame(502, $summary->fetch_status_code);
    }

    #[Test]
    public function job_rethrows_retryable_fetch_failures_before_final_attempt(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response('Bad Gateway', 502),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = $this->makeJob($summary->id, attempts: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retryable fetch failure');

        try {
            $job->handle(
                app(LinkSummaryFetcher::class),
                app(LinkSummaryGenerator::class),
            );
        } finally {
            $summary->refresh();
            $this->assertSame('fetching', $summary->processing_status);
            $this->assertNull($summary->failure_stage);
            $this->assertNull($summary->failure_reason);
        }
    }

    #[Test]
    public function job_marks_summarize_failure_when_generator_throws(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>OK</title></head><body><p>Enough body text for the fetch stage.</p></body></html>',
                200
            ),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('OpenRouter JSON invalid'));
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = $this->makeJob($summary->id, attempts: 3);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('failed', $summary->processing_status);
        $this->assertSame('summarize', $summary->failure_stage);
        $this->assertStringContainsString('OpenRouter JSON invalid', (string) $summary->failure_reason);
        $this->assertSame(200, $summary->fetch_status_code);
    }

    #[Test]
    public function job_rethrows_retryable_summarize_failures_before_final_attempt(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>OK</title></head><body><p>Enough body text for the fetch stage.</p></body></html>',
                200
            ),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('OpenRouter JSON invalid'));
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = $this->makeJob($summary->id, attempts: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter JSON invalid');

        try {
            $job->handle(
                app(LinkSummaryFetcher::class),
                app(LinkSummaryGenerator::class),
            );
        } finally {
            $summary->refresh();
            $this->assertSame('fetching', $summary->processing_status);
            $this->assertNull($summary->failure_stage);
            $this->assertNull($summary->failure_reason);
            $this->assertNull($summary->fetch_status_code);
        }
    }

    #[Test]
    public function job_treats_multibyte_short_text_as_thin_content(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Short UTF8</title></head><body><p>'.str_repeat('界', 30).'</p></body></html>',
                200
            ),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $title, string $text, string $excerpt) {
                return $title === 'Short UTF8'
                    && mb_strlen(trim($text)) < 80
                    && str_contains($text, 'Short UTF8')
                    && str_contains($text, '界界界')
                    && $excerpt === '';
            })
            ->andReturn([
                'title' => 'Short UTF8',
                'summary_text' => 'Short but still summarized.',
                'support_judgment' => 'unclear',
                'why_it_matters' => 'The page is thin.',
                'quality_notes' => null,
                'usefulness_score' => 35,
            ]);
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('summarized', $summary->processing_status);
        $this->assertSame(
            'Very little visible text extracted from the page; summary confidence is low.',
            $summary->quality_notes
        );
    }

    #[Test]
    public function job_persists_final_normalized_url_returned_by_fetcher(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/original',
            'processing_status' => 'queued',
        ]);

        $fetcher = Mockery::mock(LinkSummaryFetcher::class);
        $fetcher->shouldReceive('fetch')
            ->once()
            ->with('https://example.com/original')
            ->andReturn([
                'status_code' => 200,
                'normalized_url' => 'https://example.com/final-destination',
                'title' => 'Final page',
                'visible_text' => str_repeat('Resolved destination page text. ', 6),
                'content_fingerprint' => 'fingerprint-123',
            ]);
        $this->app->instance(LinkSummaryFetcher::class, $fetcher);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'title' => 'Final page',
                'summary_text' => 'Summary from redirected destination.',
                'support_judgment' => 'adds_context',
                'why_it_matters' => 'Redirect landed on the canonical page.',
                'quality_notes' => null,
                'usefulness_score' => 77,
            ]);
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('summarized', $summary->processing_status);
        $this->assertSame('https://example.com/final-destination', $summary->normalized_url);
        $this->assertSame(sha1('https://example.com/final-destination'), $summary->normalized_url_hash);
    }

    #[Test]
    public function job_fails_safely_when_redirected_final_url_hash_would_collide(): void
    {
        $existing = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/final-destination',
            'normalized_url_hash' => sha1('https://example.com/final-destination'),
            'processing_status' => 'queued',
        ]);

        $summary = ThoughtLinkSummary::factory()->create([
            'user_id' => $existing->user_id,
            'source_thought_id' => $existing->source_thought_id,
            'normalized_url' => 'https://example.com/original',
            'normalized_url_hash' => sha1('https://example.com/original'),
            'processing_status' => 'queued',
        ]);

        $fetcher = Mockery::mock(LinkSummaryFetcher::class);
        $fetcher->shouldReceive('fetch')
            ->once()
            ->with('https://example.com/original')
            ->andReturn([
                'status_code' => 200,
                'normalized_url' => 'https://example.com/final-destination',
                'title' => 'Final page',
                'visible_text' => str_repeat('Resolved destination page text. ', 6),
                'content_fingerprint' => 'fingerprint-123',
            ]);
        $this->app->instance(LinkSummaryFetcher::class, $fetcher);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('failed', $summary->processing_status);
        $this->assertSame('fetch', $summary->failure_stage);
        $this->assertStringContainsString('normalized URL collision', (string) $summary->failure_reason);
        $this->assertSame('https://example.com/original', $summary->normalized_url);
        $this->assertSame(sha1('https://example.com/original'), $summary->normalized_url_hash);
    }

    #[Test]
    public function job_marks_summarize_failure_when_generator_returns_blank_required_fields(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'queued',
        ]);

        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>OK</title></head><body><p>'
                .str_repeat('Enough body text for required output validation. ', 4)
                .'</p></body></html>',
                200
            ),
        ]);

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'title' => '   ',
                'summary_text' => '',
                'support_judgment' => 'supports',
                'why_it_matters' => 'Because testing.',
                'quality_notes' => null,
                'usefulness_score' => 50,
            ]);
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        $summary->refresh();
        $this->assertSame('failed', $summary->processing_status);
        $this->assertSame('summarize', $summary->failure_stage);
        $this->assertNotNull($summary->failure_reason);
        $this->assertNull($summary->processed_at);
        $this->assertNull($summary->resolved_title);
        $this->assertNull($summary->summary_text);
    }

    #[Test]
    public function job_bails_when_record_missing(): void
    {
        Http::fake();

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary(9_999_999);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        Http::assertNothingSent();
    }

    #[Test]
    public function job_bails_when_already_summarized(): void
    {
        $summary = ThoughtLinkSummary::factory()->create([
            'normalized_url' => 'https://example.com/article',
            'processing_status' => 'summarized',
            'summary_text' => 'Existing',
        ]);

        Http::fake();

        $generator = Mockery::mock(LinkSummaryGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(LinkSummaryGenerator::class, $generator);

        $job = new ProcessThoughtLinkSummary($summary->id);
        $job->handle(
            app(LinkSummaryFetcher::class),
            app(LinkSummaryGenerator::class),
        );

        Http::assertNothingSent();
        $summary->refresh();
        $this->assertSame('Existing', $summary->summary_text);
    }
}

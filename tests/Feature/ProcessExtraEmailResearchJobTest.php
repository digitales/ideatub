<?php

namespace Tests\Feature;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\EmailNewsletterResearchService;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtChunkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessExtraEmailResearchJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindOpenRouterMocks(): void
    {
        $fakeEmbedding = array_fill(0, 1536, 0.1);
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->byDefault()->andReturn($fakeEmbedding);
        $openRouter->shouldReceive('extractMetadata')->byDefault()->andReturn(['tags' => []]);
        $this->app->instance(OpenRouterService::class, $openRouter);
        $this->app->instance(
            ThoughtCaptureService::class,
            new ThoughtCaptureService($openRouter, new ThoughtChunkingService)
        );
    }

    #[Test]
    public function job_creates_research_for_stored_email_with_usable_content(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'job-msg-usable-1',
            'direction' => 'received',
            'subject' => 'Newsletter subject',
            'body_text' => str_repeat('Substantive newsletter body paragraph. ', 30),
            'from_json' => [['email' => 'news@example.com', 'name' => 'News']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $imported->thought_id = $emailThought->id;
        $imported->save();

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
        $job->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $imported->refresh();
        $emailThought->refresh();
        $this->assertNotNull($imported->research_thought_id);
        $this->assertSame('research_completed', $imported->processing_status);
        $this->assertSame('research_completed', $imported->processing_metadata_json['newsletter_research']['status'] ?? null);
        $this->assertSame('research_completed', $emailThought->source_metadata['newsletter_research']['status'] ?? null);
        $this->assertSame($imported->research_thought_id, $emailThought->source_metadata['newsletter_research']['research_thought_id'] ?? null);

        $research = Thought::query()->find($imported->research_thought_id);
        $this->assertNotNull($research);
        $this->assertSame('research', $research->source);
        $this->assertStringContainsString('Newsletter subject', $research->content);
    }

    #[Test]
    public function job_records_partial_failure_metadata_when_transcript_fetch_fails(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'job-msg-partial-1',
            'direction' => 'received',
            'subject' => 'Video newsletter',
            'body_text' => str_repeat('Enough body for non-transcript path. ', 12),
            'from_json' => [['email' => 'vid@example.com', 'name' => 'Vid']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
            'processing_metadata_json' => [
                'extracted_links' => [
                    ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'youtube'],
                ],
            ],
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $imported->thought_id = $emailThought->id;
        $imported->save();

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')
            ->once()
            ->andReturn([
                'ok' => false,
                'reason' => 'transcript_unavailable',
                'video_id' => 'dQw4w9WgXcQ',
            ]);
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
        $job->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $imported->refresh();
        $emailThought->refresh();
        $this->assertSame('research_partial', $imported->processing_status);
        $this->assertSame('research_partial', $imported->processing_metadata_json['newsletter_research']['status'] ?? null);
        $meta = $imported->processing_metadata_json ?? [];
        $this->assertTrue($meta['newsletter_research']['degraded'] ?? false);
        $this->assertNotEmpty($meta['newsletter_research']['youtube_transcripts'] ?? []);
        $this->assertSame('research_partial', $emailThought->source_metadata['newsletter_research']['status'] ?? null);
    }

    #[Test]
    public function job_skips_research_cleanly_when_input_is_insufficient(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'job-msg-skip-1',
            'direction' => 'received',
            'subject' => 'Hi',
            'body_text' => 'Hi',
            'from_json' => [['email' => 'tiny@example.com', 'name' => 'Tiny']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $imported->thought_id = $emailThought->id;
        $imported->save();

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
        $job->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $imported->refresh();
        $this->assertNull($imported->research_thought_id);
        $this->assertSame('research_skipped', $imported->processing_status);
        $this->assertSame('research_skipped', $imported->processing_metadata_json['newsletter_research']['status'] ?? null);
        $this->assertIsString($imported->processing_metadata_json['newsletter_research']['reason'] ?? null);

        $emailThought->refresh();
        $this->assertSame('research_skipped', $emailThought->source_metadata['newsletter_research']['status'] ?? null);
    }

    #[Test]
    public function job_links_research_thought_back_to_stored_email_and_email_thought(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'job-captured-link-1',
            'sender_email' => 'nl@example.com',
            'subject' => 'Link test subject',
            'body_text' => str_repeat('Body for linkage test. ', 25),
            'received_at' => now(),
            'rule_action' => 'extra_process',
            'processing_status' => 'research_queued',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'captured_inbound_email_id' => $captured->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $captured->thought_id = $emailThought->id;
        $captured->save();

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $job = new ProcessExtraEmailResearch(capturedInboundEmailId: $captured->id);
        $job->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $captured->refresh();
        $emailThought->refresh();
        $research = Thought::query()->find($captured->research_thought_id);
        $this->assertNotNull($research);

        $this->assertSame($research->id, $emailThought->source_metadata['research_thought_id']);
        $this->assertSame('research_completed', $emailThought->source_metadata['newsletter_research']['status'] ?? null);
        $this->assertSame($emailThought->id, $research->source_metadata['email_thought_id']);
        $this->assertSame($captured->id, $research->source_metadata['stored_email_id']);
        $this->assertSame('captured_inbound_email', $research->source_metadata['stored_email_type']);
        $this->assertSame('research_completed', $captured->processing_metadata_json['newsletter_research']['status'] ?? null);
    }

    #[Test]
    public function replaying_same_job_for_same_stored_email_does_not_create_second_research_thought(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'job-msg-idem-1',
            'direction' => 'received',
            'subject' => 'Idempotent',
            'body_text' => str_repeat('Stable body for idempotency. ', 20),
            'from_json' => [['email' => 'idem@example.com', 'name' => 'Idem']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $imported->thought_id = $emailThought->id;
        $imported->save();

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
        $job->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $imported->refresh();
        $firstResearchId = $imported->research_thought_id;
        $this->assertNotNull($firstResearchId);
        $countAfterFirst = Thought::query()->where('user_id', $user->id)->where('source', 'research')->count();

        $job2 = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
        $job2->handle(
            app(EmailNewsletterResearchService::class),
            app(EmailLinkExtractor::class),
        );

        $imported->refresh();
        $this->assertSame($firstResearchId, $imported->research_thought_id);
        $countAfterSecond = Thought::query()->where('user_id', $user->id)->where('source', 'research')->count();
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    #[Test]
    public function overlapping_run_with_existing_lock_releases_job_for_retry(): void
    {
        config(['app.name' => 'JobTestApp']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'job-msg-lock-1',
            'direction' => 'received',
            'subject' => 'Locked run',
            'body_text' => str_repeat('Body for lock test. ', 20),
            'from_json' => [['email' => 'lock@example.com', 'name' => 'Lock']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $imported->thought_id = $emailThought->id;
        $imported->save();

        $researchService = Mockery::mock(EmailNewsletterResearchService::class);
        $researchService->shouldReceive('createFromEmailThought')->never();

        $lock = Cache::lock('process-extra-email-research:imported:'.$imported->id, 660);
        $this->assertTrue($lock->get());

        try {
            $job = new class(importedEmailId: $imported->id) extends ProcessExtraEmailResearch
            {
                /**
                 * @var list<int>
                 */
                public array $releasedDelays = [];

                public function release($delay = 0): void
                {
                    $this->releasedDelays[] = (int) $delay;
                }
            };
            $job->handle($researchService, app(EmailLinkExtractor::class));
        } finally {
            $lock->release();
        }

        $imported->refresh();
        $this->assertNull($imported->research_thought_id);
        $this->assertSame('research_queued', $imported->processing_status);
        $this->assertDatabaseCount('thoughts', 1);
        $this->assertSame([60], $job->releasedDelays);
    }
}

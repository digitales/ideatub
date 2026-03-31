<?php

namespace Tests\Unit\Services;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailNewsletterResearchService;
use App\Services\Email\YouTubeTranscriptService;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtChunkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use League\CommonMark\CommonMarkConverter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailNewsletterResearchServiceTest extends TestCase
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
    public function creates_research_thought_from_email_thought_and_extracted_links(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-links-1',
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'body_text' => str_repeat('This is substantive newsletter body text. ', 30),
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
            'rule_email' => 'digest@example.com',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $links = [
            ['url' => 'https://example.com/article', 'type' => 'generic'],
        ];

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            $links,
        );

        $this->assertSame('created', $result['status']);
        $this->assertInstanceOf(Thought::class, $result['research_thought']);
        $this->assertStringContainsString('Weekly digest', $result['research_thought']->content);
        $this->assertStringNotContainsString('## Extracted links', $result['research_thought']->content);
        $this->assertStringNotContainsString('https://example.com/article', $result['research_thought']->content);

        $imported->refresh();
        $this->assertSame($result['research_thought']->id, $imported->research_thought_id);

        $emailThought->refresh();
        $this->assertIsString($emailThought->source_metadata['research_thought_id']);
        $this->assertSame($result['research_thought']->id, $emailThought->source_metadata['research_thought_id']);
        $this->assertSame('extra_process', $emailThought->source_metadata['sender_rule_action']);
        $this->assertSame($imported->id, $emailThought->source_metadata['imported_email_id']);

        $research = $result['research_thought']->fresh();
        $this->assertSame('research', $research->metadata['type'] ?? null);
        $rsm = $research->source_metadata ?? [];
        $this->assertSame($emailThought->id, $rsm['email_thought_id'] ?? null);
        $this->assertSame('Weekly digest', $rsm['email_subject'] ?? null);
        $this->assertSame('Digest <digest@example.com>', $rsm['email_sender'] ?? null);
        $this->assertSame('digest@example.com', $rsm['sender_email'] ?? null);
    }

    #[Test]
    public function newsletter_body_text_with_markdown_like_separators_stays_literal_when_rendered(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-markdown-literal-1',
            'direction' => 'received',
            'subject' => 'Newsletter markdown literal',
            'body_text' => "View this post on the web at https://example.com/post\n\nPermanent plus Access to My Skills Repo\n---\n\nOn March 11th, Anthropic slipped Skills into the sidebars of Excel and PowerPoint.\n\nThis trailing paragraph keeps the body long enough for research generation.",
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
            'rule_email' => 'digest@example.com',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
            ],
        ]);

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            [],
        );

        $this->assertSame('created', $result['status']);

        $html = (new CommonMarkConverter)->convert($result['research_thought']->content)->getContent();

        $this->assertStringContainsString('Permanent plus Access to My Skills Repo', $html);
        $this->assertStringNotContainsString('<h2>Permanent plus Access to My Skills Repo</h2>', $html);
        $this->assertStringContainsString('On March 11th, Anthropic slipped Skills into the sidebars of Excel and PowerPoint.', $html);
    }

    #[Test]
    public function includes_youtube_transcript_content_when_available(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-yt-1',
            'direction' => 'received',
            'subject' => 'Video issue',
            'body_text' => str_repeat('Supporting body for the newsletter. ', 15),
            'from_json' => [['email' => 'vid@example.com', 'name' => 'Vid']],
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

        $links = [
            ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'youtube'],
        ];

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')
            ->once()
            ->with('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->andReturn([
                'ok' => true,
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'en',
                'transcript' => 'Transcript line one. Transcript line two.',
            ]);
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            $links,
        );

        $this->assertSame('created', $result['status']);
        $this->assertStringContainsString('Transcript line one', $result['research_thought']->content);
        $this->assertStringContainsString('dQw4w9WgXcQ', $result['research_thought']->content);
        $this->assertStringNotContainsString('## Extracted links', $result['research_thought']->content);
    }

    #[Test]
    public function still_creates_degraded_research_when_transcript_retrieval_fails_but_body_is_sufficient(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-degraded-1',
            'direction' => 'received',
            'subject' => 'Degraded',
            'body_text' => str_repeat('Enough body text to allow degraded research without transcripts. ', 10),
            'from_json' => [['email' => 'deg@example.com', 'name' => 'Deg']],
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

        $links = [
            ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'youtube'],
        ];

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')
            ->once()
            ->andReturn([
                'ok' => false,
                'reason' => 'transcript_unavailable',
                'video_id' => 'dQw4w9WgXcQ',
            ]);
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            $links,
        );

        $this->assertSame('created', $result['status']);
        $this->assertStringNotContainsString('## Extracted links', $result['research_thought']->content);
        $this->assertTrue($result['degraded'] ?? false);
        $imported->refresh();
        $this->assertSame($result['research_thought']->id, $imported->research_thought_id);
        $meta = $imported->processing_metadata_json ?? [];
        $this->assertArrayHasKey('newsletter_research', $meta);
        $this->assertNotEmpty($meta['newsletter_research']['youtube_transcripts'] ?? []);
    }

    #[Test]
    public function skips_creating_research_when_there_is_not_enough_meaningful_content(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-skip-1',
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

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            [],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertArrayHasKey('reason', $result);
        $this->assertIsString($result['reason']);
        $this->assertNull($result['research_thought'] ?? null);

        $imported->refresh();
        $this->assertNull($imported->research_thought_id);
        $meta = $imported->processing_metadata_json ?? [];
        $this->assertSame('research_skipped', $meta['newsletter_research']['status'] ?? null);
        $this->assertSame($result['reason'], $meta['newsletter_research']['reason'] ?? null);

        $emailThought->refresh();
        $this->assertArrayNotHasKey('research_thought_id', $emailThought->source_metadata ?? []);
    }

    #[Test]
    public function stores_source_metadata_linkages_on_email_and_research_thoughts_for_postmark_capture(): void
    {
        config(['app.name' => 'PostmarkAppName']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'postmark-msg-meta-1',
            'sender_email' => 'sender@newsletter.example',
            'subject' => 'Captured subject',
            'body_text' => str_repeat('Captured inbound body with enough text. ', 25),
            'received_at' => now(),
            'rule_action' => 'extra_process',
            'rule_email' => 'sender@newsletter.example',
            'processing_status' => 'research_queued',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'captured_inbound_email_id' => $captured->id,
                'sender_rule_action' => 'extra_process',
                'message_id' => 'postmark-msg-meta-1',
            ],
        ]);

        $links = [['url' => 'https://docs.example.com/readme', 'type' => 'generic']];

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $captured,
            'postmark',
            $links,
        );

        $this->assertSame('created', $result['status']);
        $research = $result['research_thought'];
        $this->assertStringNotContainsString('## Extracted links', $research->content);
        $this->assertSame('research', $research->source);

        $sm = $research->source_metadata;
        $this->assertSame('research', $sm['doc_type'] ?? null);
        $this->assertSame($captured->id, $sm['stored_email_id'] ?? null);
        $this->assertSame('captured_inbound_email', $sm['stored_email_type'] ?? null);
        $this->assertSame($emailThought->id, $sm['email_thought_id'] ?? null);
        $this->assertSame('Captured subject', $sm['email_subject'] ?? null);
        $this->assertSame('sender@newsletter.example', $sm['email_sender'] ?? null);
        $this->assertSame('sender@newsletter.example', $sm['sender_email'] ?? null);
        $this->assertSame('postmark', $sm['ingestion_source'] ?? null);
        $this->assertSame('PostmarkAppName', $sm['project'] ?? null);

        $emailThought->refresh();
        $this->assertIsString($emailThought->source_metadata['research_thought_id']);
        $this->assertSame($research->id, $emailThought->source_metadata['research_thought_id']);
        $this->assertSame('extra_process', $emailThought->source_metadata['sender_rule_action']);
        $this->assertSame($captured->id, $emailThought->source_metadata['captured_inbound_email_id']);

        $captured->refresh();
        $this->assertSame($research->id, $captured->research_thought_id);
        $cm = $captured->processing_metadata_json ?? [];
        $this->assertArrayHasKey('newsletter_research', $cm);
        $this->assertSame($research->id, $cm['newsletter_research']['research_thought_id'] ?? null);
        $this->assertSame($emailThought->id, $cm['newsletter_research']['email_thought_id'] ?? null);
    }

    #[Test]
    public function falls_back_to_email_thought_source_metadata_for_subject_and_sender_when_stored_fields_are_empty(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-fallback-meta-1',
            'direction' => 'received',
            'subject' => '',
            'body_text' => str_repeat('This is substantive newsletter body text. ', 30),
            'from_json' => [],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
            'rule_email' => 'rule-only@example.com',
        ]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
                'subject' => 'Subject from email thought',
                'email_sender' => 'Human Name <human@example.org>',
            ],
        ]);

        $links = [
            ['url' => 'https://example.com/article', 'type' => 'generic'],
        ];

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            $links,
        );

        $this->assertSame('created', $result['status']);
        $this->assertStringNotContainsString('## Extracted links', $result['research_thought']->content);
        $rsm = $result['research_thought']->fresh()->source_metadata ?? [];
        $this->assertSame($emailThought->id, $rsm['email_thought_id'] ?? null);
        $this->assertSame('Subject from email thought', $rsm['email_subject'] ?? null);
        $this->assertSame('Human Name <human@example.org>', $rsm['email_sender'] ?? null);
        $this->assertSame('rule-only@example.com', $rsm['sender_email'] ?? null);
    }

    #[Test]
    public function skips_research_for_medium_length_text_only_email_with_no_links_when_under_word_threshold(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $body = str_repeat('x', 100);
        $this->assertGreaterThanOrEqual(80, mb_strlen($body));
        $this->assertLessThan(200, mb_strlen($body));
        $this->assertLessThan(40, str_word_count($body));

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-medium-text-only-1',
            'direction' => 'received',
            'subject' => '',
            'body_text' => $body,
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

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            [],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('insufficient_content', $result['reason'] ?? null);
        $this->assertNull($result['research_thought'] ?? null);
    }

    #[Test]
    public function creates_research_when_imported_email_from_json_is_null_using_rule_email_and_thought_sender_fallback(): void
    {
        config(['app.name' => 'TestAppNewsletter']);
        $this->bindOpenRouterMocks();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'prov-msg-null-from-json-1',
            'direction' => 'received',
            'subject' => 'No from_json',
            'body_text' => str_repeat('This is substantive newsletter body text. ', 30),
            'from_json' => [['email' => 'placeholder@example.com', 'name' => 'Placeholder']],
            'processing_status' => 'research_queued',
            'rule_action' => 'extra_process',
            'rule_email' => 'rule-fallback@example.com',
        ]);

        DB::table('imported_emails')->where('id', $imported->id)->update(['from_json' => null]);
        $imported->refresh();
        $this->assertNull($imported->from_json);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'source_metadata' => [
                'imported_email_id' => $imported->id,
                'sender_rule_action' => 'extra_process',
                'email_sender' => 'Display Name <display@example.org>',
            ],
        ]);

        $yt = Mockery::mock(YouTubeTranscriptService::class);
        $yt->shouldReceive('fetchForUrl')->never();
        $this->app->instance(YouTubeTranscriptService::class, $yt);

        $result = app(EmailNewsletterResearchService::class)->createFromEmailThought(
            $emailThought,
            $imported,
            'fastmail',
            [],
        );

        $this->assertSame('created', $result['status']);
        $rsm = $result['research_thought']->fresh()->source_metadata ?? [];
        $this->assertSame('rule-fallback@example.com', $rsm['sender_email'] ?? null);
        $this->assertSame('Display Name <display@example.org>', $rsm['email_sender'] ?? null);
    }
}

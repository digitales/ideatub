<?php

namespace Tests\Feature;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\EmailSenderRule;
use App\Models\InboxItem;
use App\Models\Thought;
use App\Models\UnmatchedInboundEmail;
use App\Models\User;
use App\Models\UserInboundAddress;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\EmailReviewInboxService;
use App\Services\Email\EmailSenderRuleService;
use App\Services\Email\EmailThoughtStreamVisibilityService;
use App\Services\OpenRouterService;
use App\Services\PostmarkInboundService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtChunkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PostmarkInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.postmark_inbound.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function webhookUrl(string $token = self::WEBHOOK_SECRET): string
    {
        return '/webhooks/postmark/inbound/'.$token;
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'From' => 'sender@example.com',
            'MessageID' => '73e6d360-66eb-11e1-8e72-a8904824019b',
            'TextBody' => '',
            'HtmlBody' => '',
        ], $overrides);
    }

    public function test_wrong_token_returns_404(): void
    {
        $response = $this->postJson($this->webhookUrl('wrong-token'), $this->minimalPayload(['TextBody' => 'Hi']));

        $response->assertStatus(404);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 0);
    }

    public function test_empty_body_returns_200_and_no_thought(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload());

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 0);
    }

    public function test_matched_user_creates_thought(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-123',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame('Hello', $thought->content);
        $this->assertSame('email', $thought->source);
        $this->assertSame('msg-123', $thought->source_metadata['message_id'] ?? null);
        $this->assertSame('sender@example.com', $thought->source_metadata['from'] ?? null);
    }

    public function test_postmark_inbound_preserves_legacy_behavior_when_sender_policy_disabled(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-legacy-off',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame('Hello', $thought->content);
        $this->assertSame('email', $thought->source);
        $this->assertSame('msg-legacy-off', $thought->source_metadata['message_id'] ?? null);
    }

    public function test_matched_postmark_email_is_stored_before_thought_creation(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $capturedInboundEmailAtThoughtCreation = null;
        $realCaptureService = new ThoughtCaptureService(
            app(OpenRouterService::class),
            app(ThoughtChunkingService::class)
        );

        $this->mock(ThoughtCaptureService::class, function ($mock) use (&$capturedInboundEmailAtThoughtCreation, $realCaptureService): void {
            $mock->shouldReceive('create')
                ->once()
                ->andReturnUsing(function (array $payload) use (&$capturedInboundEmailAtThoughtCreation, $realCaptureService) {
                    $capturedInboundEmailAtThoughtCreation = DB::table('captured_inbound_emails')
                        ->where('message_id', 'msg-store-1')
                        ->first();

                    return $realCaptureService->create($payload);
                });
        });

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-store-1',
            'Subject' => 'Stored before thought',
        ]))->assertStatus(200);

        $this->assertNotNull(
            $capturedInboundEmailAtThoughtCreation,
            'Expected captured_inbound_emails row to exist before thought creation, but no row was present when the thought was being created.'
        );
        $this->assertSame('sender@example.com', $capturedInboundEmailAtThoughtCreation->sender_email);
        $this->assertSame('Stored before thought', $capturedInboundEmailAtThoughtCreation->subject);
        $this->assertSame('Hello', $capturedInboundEmailAtThoughtCreation->body_text);

        $this->assertDatabaseHas('captured_inbound_emails', [
            'message_id' => 'msg-store-1',
            'sender_email' => 'sender@example.com',
            'subject' => 'Stored before thought',
            'body_text' => 'Hello',
        ]);
    }

    public function test_postmark_matched_user_creates_thought_when_sender_policy_enabled(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-policy-on',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame('Hello', $thought->content);
        $this->assertSame('msg-policy-on', $thought->source_metadata['message_id'] ?? null);
    }

    public function test_unmatched_sender_stores_in_unmatched(): void
    {
        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'unknown@example.com',
            'TextBody' => 'Hi',
            'MessageID' => 'msg-456',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 1);
        $unmatched = UnmatchedInboundEmail::first();
        $this->assertSame('unknown@example.com', $unmatched->from_email);
        $this->assertSame('msg-456', $unmatched->message_id);
        $this->assertSame('Hi', $unmatched->body_text);
    }

    public function test_idempotency_same_message_id(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $payload = $this->minimalPayload(['TextBody' => 'Hello', 'MessageID' => 'msg-idem']);

        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);
        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);

        $this->assertDatabaseCount('thoughts', 1);
    }

    public function test_inbound_address_matches_user(): void
    {
        $user = User::factory()->create(['email' => 'primary@example.com']);
        UserInboundAddress::create(['user_id' => $user->id, 'email' => 'alias@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Via alias')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Via alias')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'alias@example.com',
            'TextBody' => 'Via alias',
            'MessageID' => 'msg-alias',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame($user->id, $thought->user_id);
        $this->assertSame('Via alias', $thought->content);
    }

    public function test_attachment_names_in_source_metadata(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'See attachment',
            'MessageID' => 'msg-att',
            'Attachments' => [
                ['Name' => 'file.pdf', 'Content' => 'base64...', 'ContentType' => 'application/pdf'],
                ['Name' => 'screenshot.png', 'Content' => '...', 'ContentType' => 'image/png'],
            ],
        ]));

        $response->assertStatus(200);
        $thought = Thought::first();
        $this->assertArrayHasKey('attachment_names', $thought->source_metadata);
        $this->assertSame(['file.pdf', 'screenshot.png'], $thought->source_metadata['attachment_names']);
    }

    public function test_sender_policy_ignore_returns_200_and_stores_nothing(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-ignore-1',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('captured_inbound_emails', 0);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_sender_policy_unknown_sender_creates_captured_inbound_email_and_review_inbox_item(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        User::factory()->create(['email' => 'sender@example.com']);

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'See https://example.com/a',
            'MessageID' => 'msg-unknown-review',
            'Subject' => 'Unknown sender',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseHas('captured_inbound_emails', [
            'message_id' => 'msg-unknown-review',
            'sender_email' => 'sender@example.com',
            'rule_action' => 'review',
            'processing_status' => 'review_queued',
        ]);
        $captured = CapturedInboundEmail::query()->where('message_id', 'msg-unknown-review')->first();
        $this->assertNotNull($captured);
        $this->assertIsArray($captured->processing_metadata_json['extracted_links'] ?? null);
        $this->assertDatabaseCount('inbox_items', 1);
        $item = InboxItem::first();
        $this->assertSame('email_sender_review', $item->generator_type);
        $this->assertSame(
            [
                'stored_email_type' => 'captured_inbound_email',
                'stored_email_id' => $captured->id,
                'sender_email' => 'sender@example.com',
                'rule_action' => 'review',
            ],
            $item->source_data
        );
        $this->assertSame($captured->review_inbox_item_id, $item->id);
    }

    public function test_sender_policy_explicit_review_rule_creates_captured_and_review_inbox_item(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Body',
            'MessageID' => 'msg-explicit-review',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseHas('captured_inbound_emails', [
            'message_id' => 'msg-explicit-review',
            'rule_action' => 'review',
            'processing_status' => 'review_queued',
        ]);
        $this->assertDatabaseCount('inbox_items', 1);
    }

    public function test_sender_policy_allowed_sender_creates_captured_inbound_email_and_thought(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Allowed body')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Allowed body')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Allowed body',
            'MessageID' => 'msg-allow-cap',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $captured = CapturedInboundEmail::query()->where('message_id', 'msg-allow-cap')->first();
        $this->assertNotNull($captured);
        $this->assertSame('allow', $captured->rule_action);
        $this->assertSame('imported', $captured->processing_status);
        $thought = Thought::first();
        $this->assertSame($captured->id, $thought->source_metadata['captured_inbound_email_id'] ?? null);
        $this->assertSame('allow', $thought->source_metadata['sender_rule_action'] ?? null);
        $this->assertSame($thought->id, $captured->thought_id);
    }

    public function test_postmark_allow_flow_restores_stream_visibility_for_non_ignored_sender(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $this->app->instance(ThoughtCaptureService::class, new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                $thought = Thought::factory()->create([
                    'user_id' => $options['user_id'],
                    'content' => $options['content'],
                    'source' => $options['source'],
                    'source_metadata' => $options['source_metadata'] ?? null,
                    'embedding' => null,
                    'is_visible_in_stream' => false,
                    'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
                ]);

                return [
                    'thought' => $thought,
                    'chunked' => false,
                ];
            }
        });

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Stream visibility body',
            'MessageID' => 'msg-stream-visibility-allow',
        ]))->assertStatus(200);

        $thought = Thought::query()->sole();
        $this->assertTrue($thought->is_visible_in_stream);
        $this->assertNull($thought->visibility_reason);
    }

    public function test_postmark_legacy_inbound_does_not_invoke_stream_visibility_service(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $spy = $this->createMock(EmailThoughtStreamVisibilityService::class);
        $spy->expects($this->never())->method('applyToThought');
        $this->app->instance(EmailThoughtStreamVisibilityService::class, $spy);

        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-legacy-no-visibility-svc',
        ]))->assertStatus(200);
    }

    public function test_sender_policy_extra_process_creates_captured_thought_and_dispatches_research_job(): void
    {
        config(['services.email_sender_policy.enabled' => true]);
        Bus::fake();

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Extra body')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Extra body')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Extra body',
            'MessageID' => 'msg-extra-cap',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $captured = CapturedInboundEmail::query()->where('message_id', 'msg-extra-cap')->first();
        $this->assertNotNull($captured);
        $this->assertSame('extra_process', $captured->rule_action);
        $this->assertSame('research_queued', $captured->processing_status);
        $thought = Thought::first();
        $this->assertSame($captured->id, $thought->source_metadata['captured_inbound_email_id'] ?? null);
        $this->assertSame('extra_process', $thought->source_metadata['sender_rule_action'] ?? null);
        $this->assertSame('research_queued', $thought->source_metadata['newsletter_research']['status'] ?? null);

        Bus::assertDispatched(ProcessExtraEmailResearch::class, fn (ProcessExtraEmailResearch $job): bool => $job->capturedInboundEmailId === $captured->id);
        Bus::assertDispatchedTimes(ProcessExtraEmailResearch::class, 1);
    }

    public function test_sender_policy_thought_creation_forces_no_chunking_like_fastmail(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $probe = (object) ['options' => null];
        $this->app->instance(ThoughtCaptureService::class, new class($probe) extends ThoughtCaptureService
        {
            public function __construct(private object $probe) {}

            public function create(array $options): array
            {
                $this->probe->options = $options;

                return [
                    'thought' => Thought::factory()->create([
                        'user_id' => $options['user_id'],
                        'content' => $options['content'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'embedding' => null,
                    ]),
                    'chunked' => false,
                ];
            }
        });

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Body without chunking marker',
            'MessageID' => 'msg-no-chunking',
        ]))->assertStatus(200);

        $this->assertTrue($probe->options['no_chunking']);
    }

    public function test_sender_policy_extra_process_dispatch_runs_after_transaction_commit(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $this->app->instance(ThoughtCaptureService::class, new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                return [
                    'thought' => Thought::factory()->create([
                        'user_id' => $options['user_id'],
                        'content' => $options['content'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'embedding' => null,
                    ]),
                    'chunked' => false,
                ];
            }
        });

        $probe = (object) ['transactionLevel' => null, 'capturedSeenAtDispatch' => null];
        $this->app->bind(PostmarkInboundService::class, function ($app) use ($probe) {
            return new class($app->make(ThoughtCaptureService::class), $app->make(EmailSenderRuleService::class), $app->make(EmailReviewInboxService::class), $app->make(EmailLinkExtractor::class), $app->make(EmailThoughtStreamVisibilityService::class), $probe) extends PostmarkInboundService
            {
                public function __construct(
                    ThoughtCaptureService $captureService,
                    EmailSenderRuleService $senderRuleService,
                    EmailReviewInboxService $reviewInboxService,
                    EmailLinkExtractor $linkExtractor,
                    EmailThoughtStreamVisibilityService $streamVisibilityService,
                    private object $probe,
                ) {
                    parent::__construct($captureService, $senderRuleService, $reviewInboxService, $linkExtractor, $streamVisibilityService);
                }

                protected function dispatchExtraEmailResearch(CapturedInboundEmail $captured): void
                {
                    $this->probe->transactionLevel = DB::transactionLevel();
                    $this->probe->capturedSeenAtDispatch = CapturedInboundEmail::query()->find($captured->id);
                }
            };
        });

        $baselineTransactionLevel = DB::transactionLevel();

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Extra body',
            'MessageID' => 'msg-after-commit',
        ]))->assertStatus(200);

        $this->assertSame($baselineTransactionLevel, $probe->transactionLevel);
        $this->assertNotNull($probe->capturedSeenAtDispatch);
        $this->assertSame('research_queued', $probe->capturedSeenAtDispatch->processing_status);
    }

    public function test_sender_policy_missing_message_id_is_idempotent_for_duplicate_delivery(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        $this->app->instance(ThoughtCaptureService::class, new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                return [
                    'thought' => Thought::factory()->create([
                        'user_id' => $options['user_id'],
                        'content' => $options['content'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'embedding' => null,
                    ]),
                    'chunked' => false,
                ];
            }
        });

        $payload = $this->minimalPayload([
            'MessageID' => '',
            'TextBody' => 'Duplicate fallback body',
            'Subject' => 'Fallback subject',
            'Date' => '2026-03-21T12:00:00+00:00',
        ]);

        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);
        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);

        $this->assertDatabaseCount('captured_inbound_emails', 1);
        $this->assertDatabaseCount('thoughts', 1);
        $captured = CapturedInboundEmail::first();
        $this->assertStringStartsWith('postmark-fallback-', $captured->message_id);
    }

    public function test_sender_policy_extra_process_dispatch_failure_keeps_coherent_state_and_returns_200(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create(['email' => 'sender@example.com']);
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'sender@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $this->app->instance(ThoughtCaptureService::class, new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                return [
                    'thought' => Thought::factory()->create([
                        'user_id' => $options['user_id'],
                        'content' => $options['content'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'embedding' => null,
                    ]),
                    'chunked' => false,
                ];
            }
        });

        $this->app->bind(PostmarkInboundService::class, function ($app) {
            return new class($app->make(ThoughtCaptureService::class), $app->make(EmailSenderRuleService::class), $app->make(EmailReviewInboxService::class), $app->make(EmailLinkExtractor::class), $app->make(EmailThoughtStreamVisibilityService::class)) extends PostmarkInboundService
            {
                public function __construct(
                    ThoughtCaptureService $captureService,
                    EmailSenderRuleService $senderRuleService,
                    EmailReviewInboxService $reviewInboxService,
                    EmailLinkExtractor $linkExtractor,
                    EmailThoughtStreamVisibilityService $streamVisibilityService,
                ) {
                    parent::__construct($captureService, $senderRuleService, $reviewInboxService, $linkExtractor, $streamVisibilityService);
                }

                protected function dispatchExtraEmailResearch(CapturedInboundEmail $captured): void
                {
                    throw new RuntimeException('Dispatch failed');
                }
            };
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Extra body',
            'MessageID' => 'msg-dispatch-fail',
        ]));

        $response->assertStatus(200);
        $captured = CapturedInboundEmail::query()->where('message_id', 'msg-dispatch-fail')->first();
        $this->assertNotNull($captured);
        $this->assertSame('research_failed', $captured->processing_status);
        $this->assertNotNull($captured->thought_id);
        $this->assertSame('failed', $captured->processing_metadata_json['research_dispatch']['status'] ?? null);
        $this->assertSame('Dispatch failed', $captured->processing_metadata_json['research_dispatch']['message'] ?? null);
        $thought = Thought::query()->find($captured->thought_id);
        $this->assertNotNull($thought);
        $this->assertSame('research_failed', $thought->source_metadata['newsletter_research']['status'] ?? null);
        $this->assertSame('Dispatch failed', $thought->source_metadata['newsletter_research']['message'] ?? null);
        $this->assertDatabaseCount('thoughts', 1);
    }

    public function test_sender_policy_same_message_id_can_be_captured_for_different_users(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $firstUser = User::factory()->create(['email' => 'first@example.com']);
        $secondUser = User::factory()->create(['email' => 'second@example.com']);
        EmailSenderRule::create([
            'user_id' => $firstUser->id,
            'sender_email' => 'first@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
        EmailSenderRule::create([
            'user_id' => $secondUser->id,
            'sender_email' => 'second@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $this->app->instance(ThoughtCaptureService::class, new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                return [
                    'thought' => Thought::factory()->create([
                        'user_id' => $options['user_id'],
                        'content' => $options['content'],
                        'source' => $options['source'],
                        'source_metadata' => $options['source_metadata'] ?? null,
                        'embedding' => null,
                    ]),
                    'chunked' => false,
                ];
            }
        });

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'first@example.com',
            'TextBody' => 'First body',
            'MessageID' => 'msg-shared-id',
        ]))->assertStatus(200);

        $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'second@example.com',
            'TextBody' => 'Second body',
            'MessageID' => 'msg-shared-id',
        ]))->assertStatus(200);

        $this->assertDatabaseCount('captured_inbound_emails', 2);
        $this->assertDatabaseHas('captured_inbound_emails', [
            'user_id' => $firstUser->id,
            'message_id' => 'msg-shared-id',
        ]);
        $this->assertDatabaseHas('captured_inbound_emails', [
            'user_id' => $secondUser->id,
            'message_id' => 'msg-shared-id',
        ]);
    }

    public function test_sender_policy_duplicate_message_id_is_idempotent(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        User::factory()->create(['email' => 'sender@example.com']);

        $payload = $this->minimalPayload([
            'TextBody' => 'Review me',
            'MessageID' => 'msg-dup-policy',
        ]);

        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);
        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);

        $this->assertDatabaseCount('captured_inbound_emails', 1);
        $this->assertDatabaseCount('inbox_items', 1);
        $this->assertDatabaseCount('thoughts', 0);
    }
}

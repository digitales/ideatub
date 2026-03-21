<?php

namespace Tests\Unit\Services;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailBodyCleanupService;
use App\Services\Email\EmailFilterService;
use App\Services\Email\EmailImportService;
use App\Services\Email\EmailLinkExtractor;
use App\Services\Email\EmailReviewInboxService;
use App\Services\Email\EmailSenderRuleService;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\Email\ParticipantNormalizer;
use App\Services\ThoughtCaptureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailImportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function same_provider_message_id_is_stored_once_per_account(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $capture = new class extends ThoughtCaptureService
        {
            public array $calls = [];

            public function __construct() {}

            public function create(array $options): array
            {
                $this->calls[] = $options;

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
        };
        app()->instance(ThoughtCaptureService::class, $capture);
        $service = app(EmailImportService::class);

        $first = $service->importMessage($account, $this->message('msg-1'));
        $second = $service->importMessage($account, $this->message('msg-1'));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('imported_emails', 1);
        $this->assertDatabaseCount('thoughts', 1);
        $this->assertCount(1, $capture->calls);
    }

    #[Test]
    public function filtered_messages_create_one_filtered_row(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-2',
            direction: 'received',
            from: [['email' => 'no-reply@service.example', 'name' => 'No Reply']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertSame('filtered', $row->processing_status);
        $this->assertSame('bulk_sender', $row->failure_reason);
        $this->assertNull($row->thought_id);
    }

    #[Test]
    public function filtered_classification_is_sticky_on_normal_replay(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = app(EmailImportService::class);

        $first = $service->importMessage($account, $this->message(
            'msg-3',
            direction: 'received',
            from: [['email' => 'newsletter@example.com', 'name' => 'Newsletter']],
            to: [['email' => 'team@example.com', 'name' => 'Team']],
        ));
        $second = $service->importMessage($account, $this->message(
            'msg-3',
            direction: 'received',
            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('filtered', $second->processing_status);
        $this->assertSame('not_directly_addressed', $second->failure_reason);
        $this->assertDatabaseCount('imported_emails', 1);
    }

    #[Test]
    public function failed_imports_increment_failure_count_on_the_same_row(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        app()->instance(EmailBodyCleanupService::class, new class extends EmailBodyCleanupService
        {
            public function clean(?string $body): string
            {
                throw new RuntimeException('Cleanup exploded');
            }
        });

        $service = app(EmailImportService::class);

        $this->expectException(RuntimeException::class);
        try {
            $service->importMessage($account, $this->message('msg-4'));
        } finally {
            $row = ImportedEmail::where('provider_message_id', 'msg-4')->first();
            $this->assertNotNull($row);
            $this->assertSame(1, $row->failure_count);
            $this->assertSame('Cleanup exploded', $row->failure_reason);
        }
    }

    #[Test]
    public function included_messages_create_one_email_thought_via_shared_capture_flow(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $capturedThought = null;
        $capture = new class($capturedThought) extends ThoughtCaptureService
        {
            public array $calls = [];

            public function __construct(public $capturedThought = null) {}

            public function create(array $options): array
            {
                $this->calls[] = $options;
                $this->capturedThought = Thought::factory()->create([
                    'user_id' => $options['user_id'],
                    'content' => $options['content'],
                    'source' => $options['source'],
                    'source_metadata' => $options['source_metadata'] ?? null,
                    'embedding' => null,
                ]);

                return [
                    'thought' => $this->capturedThought,
                    'chunked' => false,
                ];
            }
        };
        app()->instance(ThoughtCaptureService::class, $capture);
        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-5',
            direction: 'received',
            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertNotNull($row->thought_id);
        $this->assertSame('imported', $row->processing_status);
        $this->assertCount(1, $capture->calls);
        $this->assertSame('email', $capture->calls[0]['source']);
        $this->assertTrue($capture->calls[0]['no_chunking']);
        $this->assertSame('msg-5', $capture->calls[0]['source_metadata']['provider_message_id']);
        $this->assertSame($account->id, $capture->calls[0]['source_metadata']['mail_account_id']);
        $this->assertSame($row->id, $capture->calls[0]['source_metadata']['imported_email_id']);
        $this->assertSame('received', $capture->calls[0]['source_metadata']['direction']);
        $this->assertSame('Test subject', $capture->calls[0]['source_metadata']['subject']);
        $this->assertNull($capture->calls[0]['source_metadata']['provider_mailbox_name']);
        $this->assertSame($row->thought_id, $capture->capturedThought->id);
        $this->assertSame('email', $capture->capturedThought->source);
    }

    #[Test]
    public function import_fails_cleanly_when_shared_capture_returns_no_thought(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $capture = new class extends ThoughtCaptureService
        {
            public function __construct() {}

            public function create(array $options): array
            {
                return ['chunked' => false];
            }
        };
        app()->instance(ThoughtCaptureService::class, $capture);
        $service = app(EmailImportService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Thought capture did not return a thought.');

        try {
            $service->importMessage($account, $this->message(
                'msg-6',
                direction: 'received',
                from: [['email' => 'sender@example.com', 'name' => 'Sender']],
                to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
            ));
        } finally {
            $row = ImportedEmail::where('provider_message_id', 'msg-6')->first();
            $this->assertNotNull($row);
            $this->assertSame(1, $row->failure_count);
            $this->assertSame('Thought capture did not return a thought.', $row->failure_reason);
            $this->assertNull($row->thought_id);
        }
    }

    #[Test]
    public function replay_does_not_recreate_a_deleted_email_thought(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $capture = new class extends ThoughtCaptureService
        {
            public int $calls = 0;

            public function __construct() {}

            public function create(array $options): array
            {
                $this->calls++;

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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $existing = ImportedEmail::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-7',
            'direction' => 'received',
            'processing_status' => 'imported',
            'thought_id' => null,
            'thought_deleted_at' => now(),
        ]);

        $service = app(EmailImportService::class);
        $result = $service->importMessage($account, $this->message(
            'msg-7',
            direction: 'received',
            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(0, $capture->calls);
        $this->assertDatabaseCount('thoughts', 0);
    }

    #[Test]
    public function imported_email_rows_record_sender_rule_and_linkage_metadata_when_sender_policy_enabled(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $capture = new class extends ThoughtCaptureService
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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-sender-rule-meta',
            direction: 'received',
            from: [['email' => 'Sender@Example.com', 'name' => 'Sender']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertDatabaseHas('imported_emails', [
            'id' => $row->id,
            'rule_action' => 'review',
            'rule_email' => 'sender@example.com',
            'research_thought_id' => null,
            'processing_status' => 'review_queued',
        ]);
        $this->assertNotNull($row->review_inbox_item_id);
        $this->assertDatabaseHas('inbox_items', [
            'id' => $row->review_inbox_item_id,
            'generator_type' => 'email_sender_review',
            'user_id' => $account->user_id,
        ]);
        $inbox = InboxItem::find($row->review_inbox_item_id);
        $this->assertSame('imported_email', $inbox->source_data['stored_email_type']);
        $this->assertSame($row->id, $inbox->source_data['stored_email_id']);
        $this->assertSame('sender@example.com', $inbox->source_data['sender_email']);
        $this->assertSame('review', $inbox->source_data['rule_action']);
    }

    #[Test]
    public function ignore_stores_nothing_when_sender_policy_enabled(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        EmailSenderRule::create([
            'user_id' => $account->user_id,
            'sender_email' => 'spam@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $service = app(EmailImportService::class);
        $result = $service->importMessage($account, $this->message(
            'msg-ignore',
            direction: 'received',
            from: [['email' => 'spam@example.com', 'name' => 'Spam']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertNull($result);
        $this->assertDatabaseMissing('imported_emails', [
            'provider_message_id' => 'msg-ignore',
        ]);
    }

    #[Test]
    public function review_stores_row_and_creates_no_thought_when_sender_policy_enabled(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $capture = new class extends ThoughtCaptureService
        {
            public int $calls = 0;

            public function __construct() {}

            public function create(array $options): array
            {
                $this->calls++;

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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-review',
            direction: 'received',
            from: [['email' => 'stranger@example.com', 'name' => 'Stranger']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
            bodyText: 'See https://example.com/a',
        ));

        $this->assertNotNull($row);
        $this->assertNull($row->thought_id);
        $this->assertSame('review_queued', $row->processing_status);
        $this->assertSame(0, $capture->calls);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertIsArray($row->processing_metadata_json['extracted_links'] ?? null);
    }

    #[Test]
    public function review_path_rolls_back_review_state_when_inbox_creation_fails(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        app()->instance(EmailReviewInboxService::class, new class extends EmailReviewInboxService
        {
            public function ensureForImportedEmail(User $user, ImportedEmail $importedEmail, string $ruleAction): InboxItem
            {
                throw new RuntimeException('Inbox create failed');
            }
        });

        $service = app(EmailImportService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inbox create failed');

        try {
            $service->importMessage($account, $this->message(
                'msg-review-fail',
                direction: 'received',
                from: [['email' => 'stranger@example.com', 'name' => 'Stranger']],
                to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                bodyText: 'See https://example.com/a',
            ));
        } finally {
            $row = ImportedEmail::where('provider_message_id', 'msg-review-fail')->first();
            $this->assertNotNull($row);
            $this->assertSame('pending', $row->processing_status);
            $this->assertNull($row->review_inbox_item_id);
            $this->assertNull($row->processing_metadata_json);
            $this->assertSame(1, $row->failure_count);
            $this->assertSame('Inbox create failed', $row->failure_reason);
            $this->assertSame('review', $row->rule_action);
            $this->assertSame('stranger@example.com', $row->rule_email);
            $this->assertDatabaseCount('inbox_items', 0);
        }
    }

    #[Test]
    public function explicit_review_rule_behaves_like_unknown_sender_review_routing(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        EmailSenderRule::create([
            'user_id' => $account->user_id,
            'sender_email' => 'fixed@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $capture = new class extends ThoughtCaptureService
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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $service = app(EmailImportService::class);

        $unknown = $service->importMessage($account, $this->message(
            'msg-unk',
            direction: 'received',
            from: [['email' => 'unknown@example.com', 'name' => 'U']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));
        $explicit = $service->importMessage($account, $this->message(
            'msg-exp',
            direction: 'received',
            from: [['email' => 'fixed@example.com', 'name' => 'F']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertSame('review', $unknown->rule_action);
        $this->assertSame('review', $explicit->rule_action);
        $this->assertNull($unknown->thought_id);
        $this->assertNull($explicit->thought_id);
        $this->assertNotNull($unknown->review_inbox_item_id);
        $this->assertNotNull($explicit->review_inbox_item_id);
        $this->assertDatabaseCount('inbox_items', 2);
    }

    #[Test]
    public function allow_stores_row_and_creates_thought_even_when_bulk_heuristics_would_filter(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        EmailSenderRule::create([
            'user_id' => $account->user_id,
            'sender_email' => 'no-reply@newsletter.example',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $capture = new class extends ThoughtCaptureService
        {
            public array $calls = [];

            public function __construct() {}

            public function create(array $options): array
            {
                $this->calls[] = $options;

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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-allow-bulk',
            direction: 'received',
            from: [['email' => 'no-reply@newsletter.example', 'name' => 'Newsletter']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertNotNull($row->thought_id);
        $this->assertSame('imported', $row->processing_status);
        $this->assertSame('allow', $row->rule_action);
        $this->assertSame('allow', $capture->calls[0]['source_metadata']['sender_rule_action']);
    }

    #[Test]
    public function extra_process_stores_row_creates_thought_and_dispatches_research_job(): void
    {
        config(['services.email_sender_policy.enabled' => true]);
        Bus::fake();

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        EmailSenderRule::create([
            'user_id' => $account->user_id,
            'sender_email' => 'extra@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $capture = new class extends ThoughtCaptureService
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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-extra',
            direction: 'received',
            from: [['email' => 'extra@example.com', 'name' => 'Extra']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertNotNull($row->thought_id);
        $this->assertSame('research_queued', $row->processing_status);
        $this->assertSame('extra_process', $row->rule_action);
        Bus::assertDispatched(ProcessExtraEmailResearch::class, fn (ProcessExtraEmailResearch $job): bool => $job->importedEmailId === $row->id);
        Bus::assertDispatchedTimes(ProcessExtraEmailResearch::class, 1);
    }

    #[Test]
    public function extra_process_dispatch_failure_keeps_thought_linkage_and_marks_research_failed(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        EmailSenderRule::create([
            'user_id' => $account->user_id,
            'sender_email' => 'extra@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $capture = new class extends ThoughtCaptureService
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
        };

        $service = new class(app(EmailBodyCleanupService::class), app(ParticipantNormalizer::class), app(EmailFilterService::class), $capture, app(EmailSenderRuleService::class), app(EmailReviewInboxService::class), app(EmailLinkExtractor::class)) extends EmailImportService
        {
            protected function dispatchExtraEmailResearch(ImportedEmail $row): void
            {
                throw new RuntimeException('Dispatch exploded');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dispatch exploded');

        try {
            $service->importMessage($account, $this->message(
                'msg-extra-dispatch-fail',
                direction: 'received',
                from: [['email' => 'extra@example.com', 'name' => 'Extra']],
                to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
            ));
        } finally {
            $row = ImportedEmail::where('provider_message_id', 'msg-extra-dispatch-fail')->first();
            $this->assertNotNull($row);
            $this->assertNotNull($row->thought_id);
            $this->assertSame('research_failed', $row->processing_status);
            $this->assertSame('Dispatch exploded', $row->failure_reason);
            $this->assertSame(1, $row->failure_count);
            $this->assertSame('failed', $row->processing_metadata_json['research_dispatch']['status'] ?? null);
            $this->assertSame('Dispatch exploded', $row->processing_metadata_json['research_dispatch']['message'] ?? null);
            $this->assertDatabaseCount('thoughts', 1);
        }
    }

    #[Test]
    public function duplicate_provider_message_replay_does_not_duplicate_row_or_thought_under_sender_policy(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $capture = new class extends ThoughtCaptureService
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
        };
        app()->instance(ThoughtCaptureService::class, $capture);

        $service = app(EmailImportService::class);
        $msg = $this->message(
            'msg-dup-policy',
            direction: 'received',
            from: [['email' => 'stranger@example.com', 'name' => 'Stranger']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        );

        $first = $service->importMessage($account, $msg);
        $second = $service->importMessage($account, $msg);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('imported_emails', 1);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('inbox_items', 1);
    }

    #[Test]
    public function unknown_sender_defaults_to_review_under_sender_policy(): void
    {
        config(['services.email_sender_policy.enabled' => true]);

        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = app(EmailImportService::class);

        $row = $service->importMessage($account, $this->message(
            'msg-default-review',
            direction: 'received',
            from: [['email' => 'newperson@example.com', 'name' => 'New']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertSame('review', $row->rule_action);
        $this->assertSame('newperson@example.com', $row->rule_email);
    }

    private function message(
        string $providerMessageId,
        string $direction = 'sent',
        ?string $bodyText = 'Body text',
        array $from = [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        array $to = [['email' => 'friend@example.com', 'name' => 'Friend']],
        array $cc = [],
    ): NormalizedEmailMessage {
        return new NormalizedEmailMessage(
            providerMessageId: $providerMessageId,
            providerThreadId: 'thread-'.$providerMessageId,
            providerMailboxIds: ['mb-inbox'],
            direction: $direction,
            subject: 'Test subject',
            from: $from,
            to: $to,
            cc: $cc,
            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
            bodyText: $bodyText ?? '',
        );
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Thought;
use App\Models\MailAccount;
use App\Services\Email\EmailBodyCleanupService;
use App\Services\Email\EmailImportService;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\ThoughtCaptureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        app()->instance(EmailBodyCleanupService::class, new class extends EmailBodyCleanupService {
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
            $row = \App\Models\ImportedEmail::where('provider_message_id', 'msg-4')->first();
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
            $row = \App\Models\ImportedEmail::where('provider_message_id', 'msg-6')->first();
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

        $existing = \App\Models\ImportedEmail::create([
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

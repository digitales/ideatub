<?php

namespace Tests\Feature;

use App\Jobs\BackfillMailAccount;
use App\Jobs\SyncMailAccountIncremental;
use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Services\Email\EmailBodyCleanupService;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\Fastmail\FastmailConnector;
use App\Services\ThoughtCaptureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class BackfillMailAccountJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backfill_imports_a_sent_message_and_creates_one_thought(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchBackfillBatch(MailAccount $account, array $options): array
            {
                return [
                    'messages' => [
                        new NormalizedEmailMessage(
                            providerMessageId: 'msg-1',
                            providerThreadId: 'thread-1',
                            providerMailboxIds: ['mb-sent'],
                            direction: 'sent',
                            subject: 'Sent hello',
                            from: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                            to: [['email' => 'friend@example.com', 'name' => 'Friend']],
                            cc: [],
                            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
                            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
                            bodyText: 'Body text',
                        ),
                    ],
                    'next_checkpoint' => [
                        'query_state' => 'state-1',
                        'mailbox_id' => 'mb-sent',
                    ],
                ];
            }
        };
        app()->instance(FastmailConnector::class, $connector);

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

        $job = new BackfillMailAccount($account->id);
        $job->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));

        $this->assertDatabaseHas('imported_emails', [
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg-1',
            'processing_status' => 'imported',
        ]);
        $this->assertDatabaseHas('thoughts', [
            'user_id' => $account->user_id,
            'source' => 'email',
        ]);
        $this->assertDatabaseHas('mail_sync_runs', [
            'mail_account_id' => $account->id,
            'run_type' => 'backfill',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function backfill_stores_filtered_bulk_message_without_creating_a_thought(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchBackfillBatch(MailAccount $account, array $options): array
            {
                return [
                    'messages' => [
                        new NormalizedEmailMessage(
                            providerMessageId: 'msg-bulk',
                            providerThreadId: 'thread-bulk',
                            providerMailboxIds: ['mb-inbox'],
                            direction: 'received',
                            subject: 'Newsletter',
                            from: [['email' => 'no-reply@example.com', 'name' => 'No Reply']],
                            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                            cc: [],
                            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
                            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
                            bodyText: 'Bulk body',
                        ),
                    ],
                    'next_checkpoint' => [
                        'query_state' => 'state-bulk',
                        'mailbox_id' => 'mb-inbox',
                    ],
                ];
            }
        };
        app()->instance(FastmailConnector::class, $connector);

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

        (new BackfillMailAccount($account->id))->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));

        $this->assertDatabaseHas('imported_emails', [
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg-bulk',
            'processing_status' => 'filtered',
            'failure_reason' => 'bulk_sender',
        ]);
        $this->assertSame(0, $capture->calls);
        $this->assertDatabaseCount('thoughts', 0);
    }

    #[Test]
    public function retrying_a_failed_message_increments_failure_count_on_the_same_row(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchBackfillBatch(MailAccount $account, array $options): array
            {
                return [
                    'messages' => [
                        new NormalizedEmailMessage(
                            providerMessageId: 'msg-fail',
                            providerThreadId: 'thread-fail',
                            providerMailboxIds: ['mb-inbox'],
                            direction: 'sent',
                            subject: 'Broken body',
                            from: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                            to: [['email' => 'friend@example.com', 'name' => 'Friend']],
                            cc: [],
                            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
                            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
                            bodyText: 'bad body',
                        ),
                    ],
                    'next_checkpoint' => [
                        'query_state' => 'state-fail',
                        'mailbox_id' => 'mb-inbox',
                    ],
                ];
            }
        };
        app()->instance(FastmailConnector::class, $connector);
        app()->instance(EmailBodyCleanupService::class, new class extends EmailBodyCleanupService {
            public function clean(?string $body): string
            {
                throw new RuntimeException('Cleanup exploded');
            }
        });

        $job = new BackfillMailAccount($account->id);

        foreach ([1, 2] as $attempt) {
            try {
                $job->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertDatabaseHas('imported_emails', [
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg-fail',
            'failure_count' => 2,
            'failure_reason' => 'Cleanup exploded',
        ]);
    }

    #[Test]
    public function incremental_sync_does_not_recreate_a_deleted_thought(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
            'provider_checkpoint_json' => [
                'query_state' => 'state-1',
                'mailbox_id' => 'mb-inbox',
            ],
        ]);

        ImportedEmail::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-deleted',
            'direction' => 'received',
            'processing_status' => 'imported',
            'thought_id' => null,
            'thought_deleted_at' => now(),
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchIncrementalBatch(MailAccount $account): array
            {
                return [
                    'messages' => [
                        new NormalizedEmailMessage(
                            providerMessageId: 'msg-deleted',
                            providerThreadId: 'thread-deleted',
                            providerMailboxIds: ['mb-inbox'],
                            direction: 'received',
                            subject: 'Deleted import',
                            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
                            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                            cc: [],
                            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
                            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
                            bodyText: 'Deleted body',
                        ),
                    ],
                    'next_checkpoint' => [
                        'query_state' => 'state-2',
                        'mailbox_id' => 'mb-inbox',
                    ],
                ];
            }
        };
        app()->instance(FastmailConnector::class, $connector);

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

        (new SyncMailAccountIncremental($account->id))->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));

        $this->assertSame(0, $capture->calls);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseHas('imported_emails', [
            'mail_account_id' => $account->id,
            'provider_message_id' => 'msg-deleted',
            'thought_id' => null,
        ]);
    }

    #[Test]
    public function invalid_credentials_move_account_to_needs_reauth_and_mark_run_failed(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
            'status' => 'active',
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchBackfillBatch(MailAccount $account, array $options): array
            {
                throw new InvalidMailAccountCredentialsException('Token revoked');
            }
        };
        app()->instance(FastmailConnector::class, $connector);

        (new BackfillMailAccount($account->id))->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));

        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'status' => 'needs_reauth',
        ]);
        $this->assertDatabaseHas('mail_sync_runs', [
            'mail_account_id' => $account->id,
            'run_type' => 'backfill',
            'status' => 'failed',
            'error_summary' => 'Token revoked',
        ]);
    }

    #[Test]
    public function incremental_invalid_credentials_move_account_to_needs_reauth_without_retrying(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
            'status' => 'active',
            'provider_checkpoint_json' => [
                'query_state' => 'state-1',
                'mailbox_id' => 'mb-inbox',
            ],
        ]);

        $connector = new class extends FastmailConnector
        {
            public function __construct() {}

            public function fetchIncrementalBatch(MailAccount $account): array
            {
                throw new InvalidMailAccountCredentialsException('Token revoked');
            }
        };
        app()->instance(FastmailConnector::class, $connector);

        (new SyncMailAccountIncremental($account->id))->handle(app(FastmailConnector::class), app(\App\Services\Email\EmailImportService::class));

        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'status' => 'needs_reauth',
        ]);
        $this->assertDatabaseHas('mail_sync_runs', [
            'mail_account_id' => $account->id,
            'run_type' => 'incremental',
            'status' => 'failed',
            'error_summary' => 'Token revoked',
        ]);
    }
}

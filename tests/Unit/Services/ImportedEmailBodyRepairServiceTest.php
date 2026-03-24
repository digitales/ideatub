<?php

namespace Tests\Unit\Services;

use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailBodyCleanupService;
use App\Services\Email\ImportedEmailBodyRepairService;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\Fastmail\FastmailConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportedEmailBodyRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function normalizedMessage(string $bodyText, ?string $subject = 'Test subject'): NormalizedEmailMessage
    {
        return new NormalizedEmailMessage(
            providerMessageId: 'jmap-msg-1',
            providerThreadId: null,
            providerMailboxIds: ['inbox'],
            direction: 'received',
            subject: $subject,
            from: [['email' => 'a@example.com', 'name' => 'A']],
            to: [],
            cc: [],
            sentAt: null,
            receivedAt: CarbonImmutable::now(),
            bodyText: $bodyText,
        );
    }

    private function makeRepairableRow(User $user, MailAccount $account, ?string $bodyText, array $overrides = []): ImportedEmail
    {
        return ImportedEmail::query()->create(array_merge([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'jmap-msg-1',
            'direction' => 'received',
            'subject' => 'Test subject',
            'body_text' => $bodyText,
            'from_json' => [],
            'processing_status' => 'pending',
            'rule_action' => EmailSenderRule::ACTION_ALLOW,
            'thought_id' => null,
            'thought_deleted_at' => null,
            'review_inbox_item_id' => null,
            'research_thought_id' => null,
            'rule_email' => 'sender@example.com',
            'failure_count' => 2,
            'failure_reason' => 'prior',
            'summary' => 'keep me',
            'content_fingerprint' => 'old-fp',
        ], $overrides));
    }

    #[Test]
    public function repairs_row_when_body_text_was_null(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null);

        $rawBody = "Hello from provider\n\nThanks";
        $clean = (new EmailBodyCleanupService)->clean($rawBody);
        $expectedFp = hash('sha256', implode('|', [$row->provider_message_id, $row->subject ?? '', $clean]));

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')
            ->once()
            ->with(Mockery::on(fn ($a) => $a->is($account)), $row->provider_message_id)
            ->andReturn($this->normalizedMessage($rawBody));

        $this->app->instance(FastmailConnector::class, $connector);

        $beforeCount = ImportedEmail::query()->count();
        $beforeId = $row->id;

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['repaired']);
        $this->assertFalse($result['skipped']);
        $this->assertFalse($result['dry_run']);

        $row->refresh();
        $this->assertSame($clean, $row->body_text);
        $this->assertSame($expectedFp, $row->content_fingerprint);
        $this->assertSame($beforeId, $row->id);
        $this->assertSame($beforeCount, ImportedEmail::query()->count());
        $this->assertArrayHasKey('body_repair', $row->processing_metadata_json ?? []);
    }

    #[Test]
    public function repairs_row_when_body_text_was_empty_string(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, '');

        $rawBody = 'Only new content';
        $clean = (new EmailBodyCleanupService)->clean($rawBody);
        $expectedFp = hash('sha256', implode('|', [$row->provider_message_id, $row->subject ?? '', $clean]));

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')
            ->once()
            ->andReturn($this->normalizedMessage($rawBody));

        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['repaired']);
        $row->refresh();
        $this->assertSame($clean, $row->body_text);
        $this->assertSame($expectedFp, $row->content_fingerprint);
    }

    #[Test]
    public function repairs_row_when_body_text_was_whitespace_only(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, "  \n\t  ");

        $rawBody = 'Whitespace rows should repair';
        $clean = (new EmailBodyCleanupService)->clean($rawBody);
        $expectedFp = hash('sha256', implode('|', [$row->provider_message_id, $row->subject ?? '', $clean]));

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')
            ->once()
            ->andReturn($this->normalizedMessage($rawBody));

        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['repaired']);
        $row->refresh();
        $this->assertSame($clean, $row->body_text);
        $this->assertSame($expectedFp, $row->content_fingerprint);
    }

    #[Test]
    public function preserves_protected_fields_on_repair(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);
        $inbox = InboxItem::factory()->create(['user_id' => $user->id]);

        $deletedAt = now()->subDay();
        $row = $this->makeRepairableRow($user, $account, null, [
            'thought_id' => $thought->id,
            'thought_deleted_at' => $deletedAt,
            'review_inbox_item_id' => $inbox->id,
            'research_thought_id' => $researchThought->id,
            'processing_status' => 'research_queued',
            'rule_action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
            'rule_email' => 'rule@example.com',
            'failure_count' => 5,
            'failure_reason' => 'rate_limited',
            'summary' => 'summary stays',
        ]);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn($this->normalizedMessage("Body\n\nOK"));

        $this->app->instance(FastmailConnector::class, $connector);

        app(ImportedEmailBodyRepairService::class)->repair($row);

        $row->refresh();
        $this->assertSame($thought->id, $row->thought_id);
        $this->assertEquals($deletedAt->toDateTimeString(), $row->thought_deleted_at->toDateTimeString());
        $this->assertSame($inbox->id, $row->review_inbox_item_id);
        $this->assertSame($researchThought->id, $row->research_thought_id);
        $this->assertSame('research_queued', $row->processing_status);
        $this->assertSame(EmailSenderRule::ACTION_EXTRA_PROCESS, $row->rule_action);
        $this->assertSame('rule@example.com', $row->rule_email);
        $this->assertSame(5, $row->failure_count);
        $this->assertSame('rate_limited', $row->failure_reason);
        $this->assertSame('summary stays', $row->summary);
    }

    #[Test]
    public function merges_body_repair_into_processing_metadata_without_dropping_other_keys(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, [
            'processing_metadata_json' => [
                'extracted_links' => [['url' => 'https://x.test', 'type' => 'generic']],
                'body_repair' => ['first_run' => true],
            ],
        ]);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn($this->normalizedMessage('Hello'));

        $this->app->instance(FastmailConnector::class, $connector);

        app(ImportedEmailBodyRepairService::class)->repair($row);

        $row->refresh();
        $meta = $row->processing_metadata_json;
        $this->assertSame([['url' => 'https://x.test', 'type' => 'generic']], $meta['extracted_links']);
        $this->assertTrue($meta['body_repair']['first_run']);
        $this->assertArrayHasKey('repaired_at', $meta['body_repair']);
    }

    #[Test]
    public function repair_does_not_persist_unrelated_dirty_attributes_on_passed_model(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, [
            'summary' => 'original summary',
        ]);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn($this->normalizedMessage('Hello'));
        $this->app->instance(FastmailConnector::class, $connector);

        $row->summary = 'dirty summary should not persist';

        app(ImportedEmailBodyRepairService::class)->repair($row);

        $persisted = $row->fresh();
        $this->assertSame('original summary', $persisted->summary);
        $this->assertSame('Hello', $persisted->body_text);
    }

    #[Test]
    public function skips_when_processing_status_is_filtered(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, ['processing_status' => 'filtered']);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->never();
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('filtered', $result['skip_reason']);
        $row->refresh();
        $this->assertNull($row->body_text);
    }

    #[Test]
    public function skips_when_rule_action_is_ignore(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, [
            'rule_action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->never();
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('rule_ignore', $result['skip_reason']);
    }

    #[Test]
    public function skips_when_mail_account_is_missing(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, [
            'provider_message_id' => 'orphan-msg',
        ]);

        // Simulate a row whose mail account no longer resolves (e.g. inconsistent data), without
        // relying on DB-level FK bypass (PostgreSQL session_replication_role often needs superuser).
        $row->setRelation('mailAccount', null);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->never();
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('mail_account_missing', $result['skip_reason']);
    }

    #[Test]
    public function skips_when_provider_message_cannot_be_fetched(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn(null);
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('fetch_failed', $result['skip_reason']);
        $row->refresh();
        $this->assertNull($row->body_text);
    }

    #[Test]
    public function skips_when_cleaned_body_is_still_empty(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null);

        // Only quoted lines → EmailBodyCleanupService yields empty string
        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn($this->normalizedMessage("> quoted only\n> line two"));
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('cleaned_body_empty', $result['skip_reason']);
        $row->refresh();
        $this->assertNull($row->body_text);
    }

    #[Test]
    public function dry_run_does_not_persist_changes(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->once()->andReturn($this->normalizedMessage('Would repair'));
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row, dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['repaired']);
        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['would_repair']);

        $row->refresh();
        $this->assertNull($row->body_text);
        $this->assertSame('old-fp', $row->content_fingerprint);
    }

    #[Test]
    public function second_repair_pass_does_not_create_row_or_change_id_and_skips_when_body_present(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')
            ->once()
            ->andReturn($this->normalizedMessage('First fetch body'));

        $this->app->instance(FastmailConnector::class, $connector);

        $first = app(ImportedEmailBodyRepairService::class)->repair($row);
        $this->assertTrue($first['repaired']);
        $idAfterFirst = $row->fresh()->id;
        $countAfterFirst = ImportedEmail::query()->count();

        $connector2 = Mockery::mock(FastmailConnector::class);
        $connector2->shouldReceive('fetchMessageById')->never();
        $this->app->instance(FastmailConnector::class, $connector2);

        $second = app(ImportedEmailBodyRepairService::class)->repair($row->fresh());
        $this->assertTrue($second['skipped']);
        $this->assertSame('body_present', $second['skip_reason']);

        $this->assertSame($idAfterFirst, $row->fresh()->id);
        $this->assertSame($countAfterFirst, ImportedEmail::query()->count());
    }

    #[Test]
    public function skips_non_fastmail_provider(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->makeRepairableRow($user, $account, null, ['provider' => 'other']);

        $connector = Mockery::mock(FastmailConnector::class);
        $connector->shouldReceive('fetchMessageById')->never();
        $this->app->instance(FastmailConnector::class, $connector);

        $result = app(ImportedEmailBodyRepairService::class)->repair($row);

        $this->assertTrue($result['skipped']);
        $this->assertSame('not_fastmail', $result['skip_reason']);
    }
}

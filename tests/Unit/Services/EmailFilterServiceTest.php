<?php

namespace Tests\Unit\Services;

use App\Models\MailAccount;
use App\Services\Email\EmailFilterService;
use App\Services\Email\NormalizedEmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sent_mail_is_included(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = new EmailFilterService;

        $result = $service->evaluate($account, $this->message(
            direction: 'sent',
            from: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
            to: [['email' => 'friend@example.com', 'name' => 'Friend']],
        ));

        $this->assertTrue($result['include']);
        $this->assertNull($result['reason']);
    }

    #[Test]
    public function directly_addressed_received_mail_is_included(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
            'settings_json' => [
                'aliases' => ['owner+alias@fastmail.fm'],
            ],
        ]);
        $service = new EmailFilterService;

        $result = $service->evaluate($account, $this->message(
            direction: 'received',
            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
            to: [['email' => 'owner+alias@fastmail.fm', 'name' => 'Owner Alias']],
        ));

        $this->assertTrue($result['include']);
        $this->assertNull($result['reason']);
    }

    #[Test]
    public function no_reply_and_bulk_mail_are_excluded(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = new EmailFilterService;

        $result = $service->evaluate($account, $this->message(
            direction: 'received',
            from: [['email' => 'no-reply@service.example', 'name' => 'No Reply']],
            to: [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
        ));

        $this->assertFalse($result['include']);
        $this->assertSame('bulk_sender', $result['reason']);
    }

    #[Test]
    public function non_directly_addressed_received_mail_returns_reason(): void
    {
        $account = MailAccount::factory()->create([
            'account_email' => 'owner@fastmail.fm',
        ]);
        $service = new EmailFilterService;

        $result = $service->evaluate($account, $this->message(
            direction: 'received',
            from: [['email' => 'sender@example.com', 'name' => 'Sender']],
            to: [['email' => 'team@example.com', 'name' => 'Team']],
            cc: [],
        ));

        $this->assertFalse($result['include']);
        $this->assertSame('not_directly_addressed', $result['reason']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $from
     * @param  array<int, array<string, mixed>>  $to
     * @param  array<int, array<string, mixed>>  $cc
     */
    private function message(
        string $direction,
        array $from,
        array $to,
        array $cc = [],
    ): NormalizedEmailMessage {
        return new NormalizedEmailMessage(
            providerMessageId: 'msg-1',
            providerThreadId: 'thread-1',
            providerMailboxIds: ['mb-inbox'],
            direction: $direction,
            subject: 'Test subject',
            from: $from,
            to: $to,
            cc: $cc,
            sentAt: CarbonImmutable::parse('2026-03-20T10:00:00Z'),
            receivedAt: CarbonImmutable::parse('2026-03-20T10:00:05Z'),
            bodyText: 'Body text',
        );
    }
}

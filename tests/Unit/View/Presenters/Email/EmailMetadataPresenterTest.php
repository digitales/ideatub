<?php

namespace Tests\Unit\View\Presenters\Email;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\View\Presenters\Email\EmailMetadataPresenter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailMetadataPresenterTest extends TestCase
{
    #[Test]
    public function it_prefers_imported_email_fields_over_source_metadata_fallbacks(): void
    {
        $thought = Thought::factory()->make([
            'source' => 'email',
            'source_metadata' => [
                'subject' => 'Metadata subject',
                'direction' => 'sent',
                'provider' => 'metadata-provider',
                'from' => [['email' => 'meta@example.com', 'name' => 'Meta']],
            ],
        ]);

        $imported = new ImportedEmail([
            'subject' => 'Imported subject',
            'direction' => 'received',
            'provider' => 'fastmail',
            'from_json' => [['email' => 'real@example.com', 'name' => 'Real Sender']],
        ]);
        $imported->setRelation('mailAccount', null);

        $presenter = EmailMetadataPresenter::from($thought, $imported);

        $this->assertSame('Imported subject', $presenter->subject());
        $this->assertSame('received', $presenter->direction());
        $this->assertSame('fastmail', $presenter->provider());
        $this->assertSame('Real Sender <real@example.com>', $presenter->fromLine());
    }

    #[Test]
    public function it_formats_participants_as_name_angle_brackets_email(): void
    {
        $thought = Thought::factory()->make([
            'source' => 'email',
            'source_metadata' => [],
        ]);

        $imported = new ImportedEmail([
            'from_json' => [['email' => 'a@b.com', 'name' => 'Alice']],
            'to_json' => [
                ['email' => 't1@b.com', 'name' => 'T One'],
                ['email' => 't2@b.com', 'name' => ''],
            ],
            'cc_json' => [['email' => 'c@b.com', 'name' => 'Carol']],
        ]);
        $imported->setRelation('mailAccount', null);

        $presenter = EmailMetadataPresenter::from($thought, $imported);

        $this->assertSame('Alice <a@b.com>', $presenter->fromLine());
        $this->assertSame('T One <t1@b.com>, t2@b.com', $presenter->toLine());
        $this->assertSame('Carol <c@b.com>', $presenter->ccLine());
    }

    #[Test]
    public function it_works_with_metadata_only_when_imported_email_is_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 14:30:00', 'UTC'));

        try {
            $thought = Thought::factory()->make([
                'source' => 'email',
                'source_metadata' => [
                    'subject' => 'Meta only subject',
                    'direction' => 'sent',
                    'provider' => 'postmark',
                    'provider_thread_id' => 'thr-99',
                    'provider_mailbox_name' => 'Inbox',
                    'account_email' => 'acct@example.com',
                    'from' => [['email' => 'f@example.com', 'name' => 'From Meta']],
                    'to' => [['email' => 't@example.com', 'name' => 'To Meta']],
                    'cc' => [['email' => 'c@example.com', 'name' => '']],
                    'sent_at' => '2026-03-30 10:00:00',
                ],
            ]);

            $presenter = EmailMetadataPresenter::from($thought, null);

            $this->assertSame('Meta only subject', $presenter->subject());
            $this->assertSame('sent', $presenter->direction());
            $this->assertSame('postmark', $presenter->provider());
            $this->assertSame('thr-99', $presenter->threadId());
            $this->assertSame('Inbox', $presenter->mailboxName());
            $this->assertSame('acct@example.com', $presenter->accountEmail());
            $this->assertSame('From Meta <f@example.com>', $presenter->fromLine());
            $this->assertSame('To Meta <t@example.com>', $presenter->toLine());
            $this->assertSame('c@example.com', $presenter->ccLine());
            $this->assertSame('2026-03-30 10:00:00', $presenter->sentDisplay());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function it_uses_preloaded_mail_account_email_over_metadata_account_email(): void
    {
        $thought = Thought::factory()->make([
            'source' => 'email',
            'source_metadata' => [
                'account_email' => 'metadata-acct@example.com',
            ],
        ]);

        $account = MailAccount::factory()->make(['account_email' => 'synced@fastmail.fm']);
        $imported = new ImportedEmail([]);
        $imported->setRelation('mailAccount', $account);

        $presenter = EmailMetadataPresenter::from($thought, $imported);

        $this->assertSame('synced@fastmail.fm', $presenter->accountEmail());
    }

    #[Test]
    public function it_falls_back_to_metadata_account_email_when_mail_account_relation_is_not_loaded(): void
    {
        $thought = Thought::factory()->make([
            'source' => 'email',
            'source_metadata' => [
                'account_email' => 'fallback-acct@example.com',
            ],
        ]);

        $imported = new ImportedEmail(['mail_account_id' => 1]);
        // mailAccount not set — not loaded
        $presenter = EmailMetadataPresenter::from($thought, $imported);

        $this->assertSame('fallback-acct@example.com', $presenter->accountEmail());
    }

    #[Test]
    public function it_formats_carbon_sent_and_received_for_imported_email(): void
    {
        $thought = Thought::factory()->make(['source' => 'email', 'source_metadata' => []]);
        $sent = Carbon::parse('2026-01-15 08:05:00', 'UTC');
        $received = Carbon::parse('2026-01-15 09:15:00', 'UTC');

        $imported = new ImportedEmail([
            'sent_at' => $sent,
            'received_at' => $received,
        ]);
        $imported->setRelation('mailAccount', null);

        $presenter = EmailMetadataPresenter::from($thought, $imported);

        $this->assertSame($sent->toDayDateTimeString(), $presenter->sentDisplay());
        $this->assertSame($received->toDayDateTimeString(), $presenter->receivedDisplay());
    }
}

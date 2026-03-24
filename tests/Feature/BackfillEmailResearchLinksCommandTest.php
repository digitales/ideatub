<?php

namespace Tests\Feature;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\EmailNewsletterResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEmailResearchLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(EmailNewsletterResearchService::class, function ($mock): void {
            $mock->shouldReceive('linkageFieldsForStoredEmail')
                ->andReturnUsing(function ($row, Thought $emailThought): ?array {
                    $subject = trim((string) ($row->subject ?? data_get($emailThought->source_metadata, 'subject', '')));
                    if ($subject === '') {
                        return null;
                    }

                    if ($row instanceof ImportedEmail) {
                        $from = $row->from_json[0] ?? null;
                        $email = is_array($from) ? trim((string) ($from['email'] ?? '')) : '';
                        $name = is_array($from) ? trim((string) ($from['name'] ?? '')) : '';
                        $sender = $name !== '' && $email !== ''
                            ? $name.' <'.$email.'>'
                            : ($email !== '' ? $email : trim((string) ($row->rule_email ?? '')));
                    } else {
                        $sender = trim((string) ($row->sender_email ?? data_get($emailThought->source_metadata, 'from', '')));
                    }

                    if ($sender === '') {
                        return null;
                    }

                    return [
                        'email_thought_id' => (string) $emailThought->id,
                        'email_subject' => $subject,
                        'email_sender' => $sender,
                    ];
                });
        });
    }

    public function test_command_updates_imported_email_research_metadata_when_linkage_is_missing(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'doc_type' => 'research',
            ],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'backfill-msg-1',
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest Co']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 1')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Conflicted: 0');

        $research->refresh();
        $this->assertSame($emailThought->id, $research->source_metadata['email_thought_id'] ?? null);
        $this->assertSame('Weekly digest', $research->source_metadata['email_subject'] ?? null);
        $this->assertSame('Digest Co <digest@example.com>', $research->source_metadata['email_sender'] ?? null);
        $this->assertSame($emailThought->id, $research->metadata['email_thought_id'] ?? null);
        $this->assertSame('Weekly digest', $research->metadata['email_subject'] ?? null);
        $this->assertSame('Digest Co <digest@example.com>', $research->metadata['email_sender'] ?? null);
    }

    public function test_command_updates_captured_inbound_email_research_row(): void
    {
        $user = User::factory()->create();

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [],
        ]);

        CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'captured-msg-1',
            'sender_email' => 'hello@example.com',
            'subject' => 'Inbound subject',
            'body_text' => 'Body',
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 1');

        $research->refresh();
        $this->assertSame($emailThought->id, $research->metadata['email_thought_id'] ?? null);
        $this->assertSame('Inbound subject', $research->metadata['email_subject'] ?? null);
        $this->assertSame('hello@example.com', $research->metadata['email_sender'] ?? null);
    }

    public function test_dry_run_reports_updated_without_persisting(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'dry-run-msg',
            'direction' => 'received',
            'subject' => 'Subj',
            'from_json' => [['email' => 'a@b.com', 'name' => 'A']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Updated: 1');

        $research->refresh();
        $this->assertArrayNotHasKey('email_thought_id', $research->metadata ?? []);
        $this->assertSame([], $research->source_metadata ?? []);
    }

    public function test_command_skips_when_subject_and_sender_cannot_be_resolved(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
            'source_metadata' => [],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'incomplete-msg',
            'direction' => 'received',
            'subject' => null,
            'from_json' => null,
            'rule_email' => null,
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Skipped: 1')
            ->expectsOutputToContain('Conflicted: 0');
    }

    public function test_command_counts_conflict_when_research_already_links_a_different_email_thought(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThoughtA = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);
        $emailThoughtB = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThoughtB->id,
                'email_subject' => 'Old',
                'email_sender' => 'old@example.com',
            ],
            'source_metadata' => [
                'email_thought_id' => $emailThoughtB->id,
            ],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'conflict-msg',
            'direction' => 'received',
            'subject' => 'Real subject',
            'from_json' => [['email' => 'x@y.com', 'name' => 'X']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThoughtA->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Conflicted: 1');

        $research->refresh();
        $this->assertSame($emailThoughtB->id, $research->metadata['email_thought_id'] ?? null);
    }

    public function test_command_counts_conflict_when_email_thought_id_matches_but_explicit_subject_differs(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Explicit subject that does not match imported row',
            ],
            'source_metadata' => [
                'doc_type' => 'research',
                'email_thought_id' => $emailThought->id,
                'email_subject' => 'Explicit subject that does not match imported row',
            ],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'same-id-diff-subject-msg',
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest Co']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Conflicted: 1');

        $research->refresh();
        $this->assertSame('Explicit subject that does not match imported row', $research->source_metadata['email_subject'] ?? null);
        $this->assertSame('Explicit subject that does not match imported row', $research->metadata['email_subject'] ?? null);
        $this->assertArrayNotHasKey('email_sender', $research->source_metadata ?? []);
        $this->assertArrayNotHasKey('email_sender', $research->metadata ?? []);
    }

    public function test_rows_missing_either_link_id_are_not_scanned(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'no-research-id',
            'direction' => 'received',
            'subject' => 'S',
            'from_json' => [['email' => 'a@b.com']],
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => null,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 0');
    }

    public function test_scanned_counts_both_tables(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ]);
        $prefillA = [
            'email_thought_id' => $emailThought->id,
            'email_subject' => 'Imported subject',
            'email_sender' => 'news@example.com',
        ];
        $prefillB = [
            'email_thought_id' => $emailThought->id,
            'email_subject' => 'Captured subject',
            'email_sender' => 'hello@example.com',
        ];
        $researchA = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => array_merge(['type' => 'research', 'tags' => []], $prefillA),
            'source_metadata' => $prefillA,
        ]);
        $researchB = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => array_merge(['type' => 'research', 'tags' => []], $prefillB),
            'source_metadata' => $prefillB,
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'imp-1',
            'direction' => 'received',
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'news@example.com']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchA->id,
        ]);

        CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'cap-1',
            'sender_email' => 'hello@example.com',
            'subject' => 'Captured subject',
            'body_text' => 'B',
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $researchB->id,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 2')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Skipped: 2');
    }

    public function test_command_repairs_legacy_email_sourced_idea_research_thoughts(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
            'metadata' => ['type' => 'note', 'tags' => ['godaddy']],
            'source_metadata' => [
                'subject' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
                'from' => 'GoDaddy Renewals <renewals@godaddy.com>',
            ],
        ]);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'legacy-email-research-msg',
            'direction' => 'received',
            'subject' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
            'from_json' => [['email' => 'renewals@godaddy.com', 'name' => 'GoDaddy Renewals']],
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => null,
        ]);

        $legacyResearch = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => '### Research Brief: Ross Tweedie: Your GoDaddy Renewal Notice',
            'metadata' => [
                'type' => 'research',
                'idea_id' => $emailThought->id,
                'tags' => ['research'],
            ],
            'source_metadata' => null,
        ]);

        $this->artisan('email-research:backfill-links')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Updated: 1');

        $legacyResearch->refresh();
        $emailThought->refresh();
        $stored = ImportedEmail::query()->where('thought_id', $emailThought->id)->firstOrFail();

        $this->assertSame('research', $legacyResearch->source);
        $this->assertSame($emailThought->id, $legacyResearch->metadata['email_thought_id'] ?? null);
        $this->assertSame('Ross Tweedie: Your GoDaddy Renewal Notice', $legacyResearch->metadata['email_subject'] ?? null);
        $this->assertSame('GoDaddy Renewals <renewals@godaddy.com>', $legacyResearch->metadata['email_sender'] ?? null);
        $this->assertSame($emailThought->id, $legacyResearch->source_metadata['email_thought_id'] ?? null);
        $this->assertSame($legacyResearch->id, data_get($emailThought->source_metadata, 'research_thought_id'));
        $this->assertSame($legacyResearch->id, $stored->research_thought_id);
    }
}

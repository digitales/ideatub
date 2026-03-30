<?php

namespace Tests\Feature;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillNewsletterLinkSummariesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmailThought(User $user, array $overrides = []): Thought
    {
        return Thought::factory()->create(array_merge([
            'user_id' => $user->id,
            'source' => 'email',
            'metadata' => ['type' => 'note', 'tags' => []],
        ], $overrides));
    }

    private function makeResearchThought(User $user, array $overrides = []): Thought
    {
        return Thought::factory()->create(array_merge([
            'user_id' => $user->id,
            'source' => 'research',
            'metadata' => [
                'type' => 'research',
                'tags' => [],
            ],
            'source_metadata' => [
                'doc_type' => 'research',
            ],
        ], $overrides));
    }

    private function createImportedRowWithResearch(
        User $user,
        MailAccount $account,
        Thought $emailThought,
        Thought $research,
        array $overrides = []
    ): ImportedEmail {
        return ImportedEmail::query()->create(array_merge([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'link-sum-'.Str::uuid()->toString(),
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'body_text' => str_repeat('Newsletter body paragraph. ', 20),
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest Co']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
            'processing_metadata_json' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => (string) $research->id,
                ],
            ],
        ], $overrides));
    }

    public function test_default_backfill_queues_job_for_imported_row_with_existing_research(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);
        $row = $this->createImportedRowWithResearch($user, $account, $emailThought, $research);

        $this->artisan('email-research:backfill-link-summaries')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1')
            ->expectsOutputToContain('Requeued: 0');

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row): bool {
            return $job->importedEmailId === $row->id && $job->capturedInboundEmailId === null;
        });
        Queue::assertPushed(ProcessExtraEmailResearch::class, 1);
    }

    public function test_default_backfill_queues_job_for_captured_inbound_row_with_existing_research(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);

        $row = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'captured-msg-'.Str::uuid()->toString(),
            'sender_email' => 'hello@example.com',
            'subject' => 'Inbound subject',
            'body_text' => str_repeat('Captured body. ', 20),
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $research->id,
            'processing_metadata_json' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => (string) $research->id,
                ],
            ],
        ]);

        $this->artisan('email-research:backfill-link-summaries')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1')
            ->expectsOutputToContain('Requeued: 0');

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row): bool {
            return $job->capturedInboundEmailId === $row->id && $job->importedEmailId === null;
        });
    }

    public function test_default_dry_run_reports_queued_without_dispatching_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);
        $this->createImportedRowWithResearch($user, $account, $emailThought, $research);

        $this->artisan('email-research:backfill-link-summaries', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Queued: 1');

        Queue::assertNothingPushed();
    }

    public function test_broken_research_thought_id_increments_missing_research_thought(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        // FK requires a real thought row; wrong user simulates an unlinkable research ref.
        $otherUsersResearch = $this->makeResearchThought($otherUser);

        ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'broken-ref-'.Str::uuid()->toString(),
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest Co']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => $otherUsersResearch->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries')
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Missing research thought: 1');

        Queue::assertNothingPushed();
    }

    public function test_user_id_option_limits_scan_to_that_user_rows(): void
    {
        Queue::fake();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $accountA = MailAccount::factory()->create(['user_id' => $userA->id]);
        $accountB = MailAccount::factory()->create(['user_id' => $userB->id]);

        $emailA = $this->makeEmailThought($userA);
        $researchA = $this->makeResearchThought($userA);
        $rowA = $this->createImportedRowWithResearch($userA, $accountA, $emailA, $researchA);

        $emailB = $this->makeEmailThought($userB);
        $researchB = $this->makeResearchThought($userB);
        $this->createImportedRowWithResearch($userB, $accountB, $emailB, $researchB);

        $this->artisan('email-research:backfill-link-summaries', [
            '--user-id' => (string) $userA->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1');

        Queue::assertPushed(ProcessExtraEmailResearch::class, 1);
        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($rowA): bool {
            return $job->importedEmailId === $rowA->id;
        });
    }

    public function test_limit_option_caps_how_many_rows_are_queued(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 3; $i++) {
            $emailThought = $this->makeEmailThought($user);
            $research = $this->makeResearchThought($user);
            $this->createImportedRowWithResearch($user, $account, $emailThought, $research, [
                'provider_message_id' => 'limit-row-'.$i.'-'.Str::uuid()->toString(),
            ]);
        }

        $this->artisan('email-research:backfill-link-summaries', ['--limit' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1');

        Queue::assertPushed(ProcessExtraEmailResearch::class, 1);
    }

    public function test_stored_type_imported_skips_captured_rows(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailImported = $this->makeEmailThought($user);
        $researchImported = $this->makeResearchThought($user);
        $imported = $this->createImportedRowWithResearch($user, $account, $emailImported, $researchImported);

        $emailCaptured = $this->makeEmailThought($user);
        $researchCaptured = $this->makeResearchThought($user);
        CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'cap-only-'.Str::uuid()->toString(),
            'sender_email' => 'cap@example.com',
            'subject' => 'Cap subject',
            'body_text' => str_repeat('Cap body. ', 20),
            'processing_status' => 'research_completed',
            'thought_id' => $emailCaptured->id,
            'research_thought_id' => $researchCaptured->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', ['--stored-type' => 'imported'])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1');

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($imported): bool {
            return $job->importedEmailId === $imported->id && $job->capturedInboundEmailId === null;
        });
    }

    public function test_stored_type_captured_skips_imported_rows(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailImported = $this->makeEmailThought($user);
        $researchImported = $this->makeResearchThought($user);
        $this->createImportedRowWithResearch($user, $account, $emailImported, $researchImported);

        $emailCaptured = $this->makeEmailThought($user);
        $researchCaptured = $this->makeResearchThought($user);
        $captured = CapturedInboundEmail::query()->create([
            'user_id' => $user->id,
            'message_id' => 'cap-target-'.Str::uuid()->toString(),
            'sender_email' => 'cap@example.com',
            'subject' => 'Cap subject',
            'body_text' => str_repeat('Cap body. ', 20),
            'processing_status' => 'research_completed',
            'thought_id' => $emailCaptured->id,
            'research_thought_id' => $researchCaptured->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', ['--stored-type' => 'captured'])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Queued: 1');

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($captured): bool {
            return $job->capturedInboundEmailId === $captured->id && $job->importedEmailId === null;
        });
    }

    public function test_requeue_resets_stored_row_clears_metadata_deletes_stale_summaries_and_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);
        $row = $this->createImportedRowWithResearch($user, $account, $emailThought, $research);

        $emailThought->update([
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => (string) $research->id,
                ],
            ],
        ]);

        $staleSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', ['--requeue' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Requeued: 1');

        $row->refresh();
        $emailThought->refresh();

        $this->assertNull($row->research_thought_id);
        $this->assertSame('research_queued', $row->processing_status);
        $this->assertDatabaseMissing('thought_link_summaries', ['id' => $staleSummary->id]);
        $this->assertNull(data_get($emailThought->source_metadata, 'newsletter_research'));

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row): bool {
            return $job->importedEmailId === $row->id && $job->capturedInboundEmailId === null;
        });
    }

    public function test_requeue_with_null_research_thought_id_skips_summary_deletion_but_still_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user, [
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                ],
            ],
        ]);
        $orphanResearch = $this->makeResearchThought($user);

        $row = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'null-ref-'.Str::uuid()->toString(),
            'direction' => 'received',
            'subject' => 'Weekly digest',
            'body_text' => str_repeat('Body. ', 20),
            'from_json' => [['email' => 'digest@example.com', 'name' => 'Digest Co']],
            'processing_status' => 'research_completed',
            'thought_id' => $emailThought->id,
            'research_thought_id' => null,
        ]);

        $summary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $orphanResearch->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', ['--requeue' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Requeued: 1');

        $this->assertDatabaseHas('thought_link_summaries', ['id' => $summary->id]);
        $row->refresh();
        $emailThought->refresh();
        $this->assertNull($row->research_thought_id);
        $this->assertSame('research_queued', $row->processing_status);
        $this->assertNull(data_get($emailThought->source_metadata, 'newsletter_research'));

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row): bool {
            return $job->importedEmailId === $row->id;
        });
    }

    public function test_dry_run_requeue_reports_requeued_without_mutating_data(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);
        $row = $this->createImportedRowWithResearch($user, $account, $emailThought, $research);

        $emailThought->update([
            'source_metadata' => [
                'newsletter_research' => [
                    'status' => 'research_completed',
                    'research_thought_id' => (string) $research->id,
                ],
            ],
        ]);

        $staleSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $research->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', [
            '--dry-run' => true,
            '--requeue' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Requeued: 1');

        Queue::assertNothingPushed();

        $row->refresh();
        $emailThought->refresh();
        $this->assertSame($research->id, $row->research_thought_id);
        $this->assertSame('research_completed', $row->processing_status);
        $this->assertDatabaseHas('thought_link_summaries', ['id' => $staleSummary->id]);
        $this->assertNotNull(data_get($emailThought->source_metadata, 'newsletter_research'));
    }

    public function test_requeue_leaves_unrelated_thought_link_summaries_intact(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $emailThought = $this->makeEmailThought($user);
        $research = $this->makeResearchThought($user);
        $row = $this->createImportedRowWithResearch($user, $account, $emailThought, $research);

        $staleSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $research->id,
        ]);

        $otherParent = Thought::factory()->create(['user_id' => $user->id]);
        $unrelatedSummary = ThoughtLinkSummary::factory()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $otherParent->id,
        ]);

        $this->artisan('email-research:backfill-link-summaries', ['--requeue' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('thought_link_summaries', ['id' => $staleSummary->id]);
        $this->assertDatabaseHas('thought_link_summaries', ['id' => $unrelatedSummary->id]);

        Queue::assertPushed(ProcessExtraEmailResearch::class, function (ProcessExtraEmailResearch $job) use ($row): bool {
            return $job->importedEmailId === $row->id;
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\EmailSenderRule;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Email\ImportedEmailBodyRepairService;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\Fastmail\FastmailConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RepairImportedEmailBodiesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createEligibleRow(MailAccount $account, array $overrides = []): ImportedEmail
    {
        $data = array_merge([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-'.uniqid('', true),
            'direction' => 'received',
            'subject' => 'Subject',
            'from_json' => [['email' => 'a@b.com', 'name' => 'A']],
            'processing_status' => 'imported',
            'body_text' => null,
            'rule_action' => null,
        ], $overrides);

        return ImportedEmail::query()->create($data);
    }

    public function test_dry_run_reports_eligible_rows_without_persisting_changes(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->createEligibleRow($account);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock) use ($row): void {
            $mock->shouldReceive('repair')
                ->once()
                ->withArgs(fn ($r, $dry): bool => $r->is($row) && $dry === true)
                ->andReturn([
                    'repaired' => false,
                    'skipped' => false,
                    'dry_run' => true,
                    'would_repair' => true,
                ]);
        });

        $this->artisan('emails:repair-imported-bodies', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failed: 0');

        $row->refresh();
        $this->assertNull($row->body_text);
    }

    public function test_limit_one_repairs_only_one_row(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $this->createEligibleRow($account, ['provider_message_id' => 'first']);
        $this->createEligibleRow($account, ['provider_message_id' => 'second']);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock): void {
            $mock->shouldReceive('repair')->once()->andReturn([
                'repaired' => true,
                'skipped' => false,
                'dry_run' => false,
                'would_repair' => false,
            ]);
        });

        $this->artisan('emails:repair-imported-bodies', ['--limit' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1');
    }

    public function test_mail_account_id_scopes_to_one_account(): void
    {
        $user = User::factory()->create();
        $accountA = MailAccount::factory()->create(['user_id' => $user->id]);
        $accountB = MailAccount::factory()->create(['user_id' => $user->id]);
        $rowA = $this->createEligibleRow($accountA, ['provider_message_id' => 'scope-a']);
        $this->createEligibleRow($accountB, ['provider_message_id' => 'scope-b']);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock) use ($rowA): void {
            $mock->shouldReceive('repair')
                ->once()
                ->withArgs(fn ($r, $dry): bool => $r->is($rowA) && $dry === false)
                ->andReturn([
                    'repaired' => true,
                    'skipped' => false,
                    'dry_run' => false,
                    'would_repair' => false,
                ]);
        });

        $this->artisan('emails:repair-imported-bodies', [
            '--mail-account-id' => (string) $accountA->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1')
            ->expectsOutputToContain('Skipped: 0');
    }

    public function test_invalid_mail_account_id_fails_cleanly(): void
    {
        $this->mock(ImportedEmailBodyRepairService::class, function ($mock): void {
            $mock->shouldReceive('repair')->never();
        });

        $this->artisan('emails:repair-imported-bodies', [
            '--mail-account-id' => 'abc',
        ])
            ->expectsOutputToContain('The --mail-account-id option must be a numeric mail account id.')
            ->assertExitCode(2);
    }

    public function test_filtered_rows_are_skipped_by_selection(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $this->createEligibleRow($account, [
            'provider_message_id' => 'filtered',
            'processing_status' => 'filtered',
        ]);
        $eligible = $this->createEligibleRow($account, ['provider_message_id' => 'ok']);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock) use ($eligible): void {
            $mock->shouldReceive('repair')
                ->once()
                ->withArgs(fn ($r, $dry): bool => $r->is($eligible))
                ->andReturn([
                    'repaired' => true,
                    'skipped' => false,
                    'dry_run' => false,
                    'would_repair' => false,
                ]);
        });

        $this->artisan('emails:repair-imported-bodies')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1');
    }

    public function test_ignored_rule_action_rows_are_skipped_by_selection(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $this->createEligibleRow($account, [
            'provider_message_id' => 'ignored',
            'rule_action' => EmailSenderRule::ACTION_IGNORE,
        ]);
        $eligible = $this->createEligibleRow($account, ['provider_message_id' => 'allowed']);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock) use ($eligible): void {
            $mock->shouldReceive('repair')
                ->once()
                ->withArgs(fn ($r, $dry): bool => $r->is($eligible))
                ->andReturn([
                    'repaired' => true,
                    'skipped' => false,
                    'dry_run' => false,
                    'would_repair' => false,
                ]);
        });

        $this->artisan('emails:repair-imported-bodies')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1');
    }

    public function test_rerun_does_not_reprocess_repaired_rows(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->createEligibleRow($account, [
            'provider_message_id' => 'rerun-msg',
            'subject' => 'Rerun subject',
        ]);

        $message = new NormalizedEmailMessage(
            providerMessageId: 'rerun-msg',
            providerThreadId: null,
            providerMailboxIds: [],
            direction: 'received',
            subject: 'Rerun subject',
            from: [],
            to: [],
            cc: [],
            sentAt: null,
            receivedAt: CarbonImmutable::now(),
            bodyText: 'Stable body content for repair.',
        );

        $this->mock(FastmailConnector::class, function ($mock) use ($account, $message): void {
            $mock->shouldReceive('fetchMessageById')
                ->with(Mockery::on(fn ($a) => $a->is($account)), 'rerun-msg')
                ->once()
                ->andReturn($message);
        });

        $this->artisan('emails:repair-imported-bodies', ['--limit' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1');

        $row->refresh();
        $this->assertSame('Stable body content for repair.', $row->body_text);
        $fingerprintAfterFirst = $row->content_fingerprint;

        $this->artisan('emails:repair-imported-bodies', ['--limit' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 0')
            ->expectsOutputToContain('Skipped: 0');

        $row->refresh();
        $this->assertSame('Stable body content for repair.', $row->body_text);
        $this->assertSame($fingerprintAfterFirst, $row->content_fingerprint);
    }

    public function test_output_reports_failed_rows_and_continues(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $this->createEligibleRow($account, ['provider_message_id' => 'ok-row']);
        $this->createEligibleRow($account, ['provider_message_id' => 'bad-row']);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock): void {
            $mock->shouldReceive('repair')
                ->twice()
                ->andReturnUsing(function (ImportedEmail $row): array {
                    if ($row->provider_message_id === 'bad-row') {
                        throw new \RuntimeException('simulated repair failure');
                    }

                    return [
                        'repaired' => true,
                        'skipped' => false,
                        'dry_run' => false,
                        'would_repair' => false,
                    ];
                });
        });

        $this->artisan('emails:repair-imported-bodies', ['--limit' => 10])
            ->assertExitCode(1)
            ->expectsOutputToContain('Repaired: 1')
            ->expectsOutputToContain('Failed: 1');
    }

    public function test_whitespace_only_body_text_is_selected_and_delegated_consistently(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $row = $this->createEligibleRow($account, [
            'provider_message_id' => 'whitespace-row',
            'body_text' => " \n\t ",
        ]);

        $this->mock(ImportedEmailBodyRepairService::class, function ($mock) use ($row): void {
            $mock->shouldReceive('repair')
                ->withArgs(fn ($candidate, $dry): bool => $candidate->is($row) && $dry === false)
                ->once()
                ->andReturn([
                    'repaired' => true,
                    'skipped' => false,
                    'dry_run' => false,
                    'would_repair' => false,
                ]);
        });

        $this->artisan('emails:repair-imported-bodies', ['--limit' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failed: 0');
    }
}

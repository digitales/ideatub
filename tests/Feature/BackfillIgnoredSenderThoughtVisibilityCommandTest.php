<?php

namespace Tests\Feature;

use App\Jobs\ReconcileIgnoredSenderThoughtVisibility;
use App\Models\EmailSenderRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BackfillIgnoredSenderThoughtVisibilityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_one_reconciliation_job_per_ignored_sender_rule(): void
    {
        Bus::fake();
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'alpha@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'beta@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $this->artisan('email:backfill-ignored-sender-thought-visibility')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileIgnoredSenderThoughtVisibility::class, 2);
        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'alpha@example.com';
        });
        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'beta@example.com';
        });
    }

    public function test_command_skips_non_ignored_rules(): void
    {
        Bus::fake();
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'ignored@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'allowed@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $this->artisan('email:backfill-ignored-sender-thought-visibility')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileIgnoredSenderThoughtVisibility::class, 1);
        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'ignored@example.com';
        });
    }

    public function test_command_is_safe_when_no_ignored_rules_exist(): void
    {
        Bus::fake();
        config(['services.email_sender_policy.enabled' => true]);

        $user = User::factory()->create();
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'review@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $this->artisan('email:backfill-ignored-sender-thought-visibility')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_command_no_ops_when_sender_policy_is_disabled(): void
    {
        Bus::fake();
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();
        EmailSenderRule::query()->create([
            'user_id' => $user->id,
            'sender_email' => 'ignored@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $this->artisan('email:backfill-ignored-sender-thought-visibility')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }
}

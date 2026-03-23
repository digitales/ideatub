<?php

namespace Tests\Feature;

use App\Jobs\ReconcileIgnoredSenderThoughtVisibility;
use App\Models\EmailSenderRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EmailSenderRuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.email_sender_policy.enabled' => true]);
    }

    public function test_sender_rules_settings_returns_404_when_sender_policy_disabled(): void
    {
        config(['services.email_sender_policy.enabled' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.email-sender-rules.index'))
            ->assertNotFound();
    }

    public function test_settings_page_requires_authentication(): void
    {
        $this->get(route('settings.email-sender-rules.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_email_sender_rules_settings_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.email-sender-rules.index'))
            ->assertOk()
            ->assertSee('Email sender rules');
    }

    public function test_authenticated_user_can_store_sender_rule(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
            'sender_email' => '  NatesNewsletter@Substack.com  ',
            'action' => 'extra_process',
        ]);

        $response->assertRedirect(route('settings.email-sender-rules.index'));
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'natesnewsletter@substack.com',
            'action' => 'extra_process',
        ]);
    }

    public function test_duplicate_sender_rule_for_same_user_is_rejected(): void
    {
        $user = User::factory()->create();

        $user->emailSenderRules()->create([
            'sender_email' => 'dup@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $response = $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
            'sender_email' => '  DUP@example.com  ',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $response->assertRedirect(route('settings.email-sender-rules.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('email_sender_rules', 1);
        $this->assertDatabaseHas('email_sender_rules', [
            'user_id' => $user->id,
            'sender_email' => 'dup@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    public function test_user_can_update_their_sender_rule(): void
    {
        $user = User::factory()->create();
        $rule = $user->emailSenderRules()->create([
            'sender_email' => 'update-me@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $response = $this->actingAs($user)->patch(
            route('settings.email-sender-rules.update', $rule),
            ['action' => EmailSenderRule::ACTION_EXTRA_PROCESS]
        );

        $response->assertRedirect(route('settings.email-sender-rules.index'));
        $this->assertDatabaseHas('email_sender_rules', [
            'id' => $rule->id,
            'user_id' => $user->id,
            'sender_email' => 'update-me@example.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);
    }

    public function test_user_cannot_update_another_users_sender_rule(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $rule = $owner->emailSenderRules()->create([
            'sender_email' => 'owned@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $this->actingAs($other)
            ->patch(route('settings.email-sender-rules.update', $rule), [
                'action' => EmailSenderRule::ACTION_IGNORE,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('email_sender_rules', [
            'id' => $rule->id,
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);
    }

    public function test_user_can_delete_their_sender_rule(): void
    {
        $user = User::factory()->create();
        $rule = $user->emailSenderRules()->create([
            'sender_email' => 'remove-me@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $response = $this->actingAs($user)->delete(
            route('settings.email-sender-rules.destroy', $rule)
        );

        $response->assertRedirect(route('settings.email-sender-rules.index'));
        $this->assertDatabaseMissing('email_sender_rules', ['id' => $rule->id]);
    }

    public function test_user_cannot_delete_another_users_sender_rule(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $rule = $owner->emailSenderRules()->create([
            'sender_email' => 'owned@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $this->actingAs($other)
            ->delete(route('settings.email-sender-rules.destroy', $rule))
            ->assertForbidden();

        $this->assertDatabaseHas('email_sender_rules', ['id' => $rule->id]);
    }

    public function test_store_dispatches_reconcile_visibility_job_with_normalized_sender(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
            'sender_email' => '  NatesNewsletter@Substack.com  ',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ])->assertRedirect(route('settings.email-sender-rules.index'));

        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'natesnewsletter@substack.com';
        });
    }

    public function test_update_dispatches_reconcile_visibility_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $rule = $user->emailSenderRules()->create([
            'sender_email' => 'update-me@example.com',
            'action' => EmailSenderRule::ACTION_REVIEW,
        ]);

        $this->actingAs($user)->patch(
            route('settings.email-sender-rules.update', $rule),
            ['action' => EmailSenderRule::ACTION_IGNORE]
        )->assertRedirect(route('settings.email-sender-rules.index'));

        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'update-me@example.com';
        });
    }

    public function test_destroy_dispatches_reconcile_visibility_job_with_deleted_sender(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $rule = $user->emailSenderRules()->create([
            'sender_email' => 'remove-me@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $this->actingAs($user)->delete(
            route('settings.email-sender-rules.destroy', $rule)
        )->assertRedirect(route('settings.email-sender-rules.index'));

        Bus::assertDispatched(ReconcileIgnoredSenderThoughtVisibility::class, function (ReconcileIgnoredSenderThoughtVisibility $job) use ($user): bool {
            return $job->userId === $user->id && $job->senderEmail === 'remove-me@example.com';
        });
    }
}

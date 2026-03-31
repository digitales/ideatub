<?php

namespace Tests\Feature;

use App\Jobs\BackfillMailAccount;
use App\Jobs\SyncMailAccountIncremental;
use App\Models\MailAccount;
use App\Models\MailSyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_email_accounts_page_requires_auth(): void
    {
        $response = $this->get(route('settings.email-accounts.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_email_accounts_page(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

        $response->assertOk();
        $response->assertSee('Email Accounts');
        $response->assertSee('Fastmail');
    }

    public function test_user_can_store_fastmail_account(): void
    {
        Bus::fake();
        $user = User::factory()->create(['email' => 'owner@example.com']);
        Http::fake([
            'https://api.fastmail.com/jmap/session' => Http::response([
                'username' => 'owner@fastmail.fm',
                'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                'accounts' => [
                    'u123' => [
                        'name' => 'owner@fastmail.fm',
                    ],
                ],
                'primaryAccounts' => [
                    'urn:ietf:params:jmap:mail' => 'u123',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->post(route('settings.email-accounts.store'), [
            'provider' => 'fastmail',
            'display_name' => 'Primary Fastmail',
            'account_email' => 'owner@fastmail.fm',
            'credential' => 'secret-value',
            'sync_enabled' => '1',
            'include_sent' => '1',
            'include_received_personal' => '1',
            'exclude_bulk' => '1',
            'initial_backfill_window_days' => '90',
        ]);

        $response->assertRedirect(route('settings.email-accounts.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('mail_accounts', [
            'user_id' => $user->id,
            'provider' => 'fastmail',
            'account_email' => 'owner@fastmail.fm',
        ]);
        Bus::assertDispatched(BackfillMailAccount::class);
    }

    public function test_email_accounts_page_respects_feature_flag(): void
    {
        config(['services.mail_sync.enabled' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

        $response->assertNotFound();
    }

    public function test_user_can_disconnect_account(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete(route('settings.email-accounts.destroy', $account));

        $response->assertRedirect(route('settings.email-accounts.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
    }

    public function test_user_cannot_disconnect_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->delete(route('settings.email-accounts.destroy', $account));

        $response->assertForbidden();
        $this->assertDatabaseHas('mail_accounts', ['id' => $account->id]);
    }

    public function test_user_cannot_trigger_backfill_for_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->post(route('settings.email-accounts.backfill', $account));

        $response->assertForbidden();
    }

    public function test_user_cannot_trigger_sync_for_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->post(route('settings.email-accounts.sync', $account));

        $response->assertForbidden();
    }

    public function test_owner_can_trigger_backfill(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('settings.email-accounts.backfill', $account));

        $response->assertRedirect(route('settings.email-accounts.index'));
        Bus::assertDispatched(BackfillMailAccount::class);
    }

    public function test_owner_can_trigger_sync_now(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('settings.email-accounts.sync', $account));

        $response->assertRedirect(route('settings.email-accounts.index'));
        Bus::assertDispatched(SyncMailAccountIncremental::class);
    }

    public function test_validation_errors_are_rendered_on_the_settings_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.email-accounts.index'))
            ->post(route('settings.email-accounts.store'), [
                'provider' => 'fastmail',
                'display_name' => '',
                'account_email' => 'not-an-email',
                'credential' => '',
                'initial_backfill_window_days' => '12',
            ]);

        $response->assertRedirect(route('settings.email-accounts.index'));
        $response->assertSessionHasErrors(['display_name', 'account_email', 'credential', 'initial_backfill_window_days']);

        $followed = $this->actingAs($user)->get(route('settings.email-accounts.index'));
        $followed->assertSee('Display name');
    }

    public function test_connected_account_page_shows_latest_sync_status(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create([
            'user_id' => $user->id,
            'display_name' => 'Primary Fastmail',
            'account_email' => 'owner@fastmail.fm',
        ]);

        MailSyncRun::create([
            'mail_account_id' => $account->id,
            'run_type' => 'backfill',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'stats_json' => ['imported' => 3],
            'error_summary' => null,
        ]);

        $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

        $response->assertOk();
        $response->assertSee('Latest sync');
        $response->assertSee('completed');
        $response->assertSee('owner@fastmail.fm');
    }

    public function test_email_accounts_index_does_not_lazy_load_mail_account_relations(): void
    {
        MailAccount::preventLazyLoading();

        try {
            $user = User::factory()->create();
            MailAccount::factory()->create(['user_id' => $user->id]);

            $response = $this->actingAs($user)->get(route('settings.email-accounts.index'));

            $response->assertOk();
        } finally {
            MailAccount::preventLazyLoading(false);
        }
    }
}

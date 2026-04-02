<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_profile_page_requires_authentication(): void
    {
        $response = $this->get(route('settings.profile.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_profile_page_loads_for_authenticated_user(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create([
            'name' => 'Profile Owner',
            'email' => 'owner@example.com',
        ]);

        $response = $this->actingAs($user)->get(route('settings.profile.index'));

        $response->assertOk();
        $response->assertSee('Profile');
        $response->assertSee('Profile Owner');
        $response->assertSee('owner@example.com');
        $response->assertSee('Demo mode');
        $response->assertSee('Enable demo mode');
    }

    public function test_account_menu_includes_profile_link(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee(route('settings.profile.index'), false);
        $response->assertSee('Profile');
    }

    public function test_account_menu_keeps_only_profile_inbox_shared_documents_and_logout_for_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee(route('settings.profile.index'), false);
        $response->assertSee(route('inbox.index'), false);
        $response->assertSee(route('shared-research.index'), false);
        $response->assertSee('Shared documents', false);
        $response->assertSee('Log out');

        $response->assertDontSee(route('settings.mcp-keys.index'), false);
        $response->assertDontSee(route('settings.inbound-emails.index'), false);
        $response->assertDontSee(route('settings.ideas-revisit.index'), false);
        $response->assertDontSee(route('settings.research-skills.index'), false);

        if (config('services.mail_sync.enabled', true)) {
            $response->assertDontSee(route('settings.email-accounts.index'), false);
        }

        if (config('services.email_sender_policy.enabled')) {
            $response->assertDontSee(route('settings.email-sender-rules.index'), false);
        }

        if (config('services.jira.enabled', true)) {
            $response->assertDontSee(route('settings.jira.index'), false);
        }
    }

    public function test_user_can_update_their_profile_details(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user)->put(route('settings.profile.update'), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $response->assertRedirect(route('settings.profile.index'));
        $response->assertSessionHas('success', 'Profile updated.');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_profile_update_requires_unique_email(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);
        User::factory()->create([
            'email' => 'taken@example.com',
        ]);

        $response = $this->actingAs($user)
            ->from(route('settings.profile.index'))
            ->put(route('settings.profile.update'), [
                'name' => 'Still Owner',
                'email' => 'taken@example.com',
            ]);

        $response->assertRedirect(route('settings.profile.index'));
        $response->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertSame('owner@example.com', $user->email);
    }

    public function test_profile_page_shows_exit_demo_mode_when_session_is_enabled(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'profile-demo-seed',
        ])->actingAs($user)->get(route('settings.profile.index'));

        $response->assertOk();
        $response->assertSee('Exit demo mode');
        $response->assertDontSee('Enable demo mode');
    }

    public function test_profile_page_lists_moved_settings_links(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile.index'));

        $response->assertOk();
        $response->assertSee(route('settings.research-skills.index'), false);
        $response->assertSee(route('settings.mcp-keys.index'), false);
        $response->assertSee(route('settings.inbound-emails.index'), false);
        $response->assertSee(route('settings.ideas-revisit.index'), false);

        if (config('services.mail_sync.enabled', true)) {
            $response->assertSee(route('settings.email-accounts.index'), false);
        }

        if (config('services.email_sender_policy.enabled')) {
            $response->assertSee(route('settings.email-sender-rules.index'), false);
        }

        if (config('services.jira.enabled', true)) {
            $response->assertSee(route('settings.jira.index'), false);
        }
    }
}

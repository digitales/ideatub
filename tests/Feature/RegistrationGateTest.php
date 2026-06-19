<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RegistrationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('registration.enabled', true);
        config()->set('registration.beta_access_code', null);
    }

    public function test_register_page_redirects_when_registration_is_closed(): void
    {
        config()->set('registration.enabled', false);

        $response = $this->get(route('register'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
    }

    public function test_register_page_shows_beta_access_code_field_when_required(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('Beta access code', false);
    }

    public function test_email_registration_requires_valid_beta_access_code(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $response = $this->post(route('register'), [
            'name' => 'Beta Tester',
            'email' => 'beta@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'beta_code' => 'wrong-code',
        ]);

        $response->assertSessionHasErrors('beta_code');
        $this->assertDatabaseMissing('users', ['email' => 'beta@example.com']);
    }

    public function test_email_registration_succeeds_with_valid_beta_access_code(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $response = $this->post(route('register'), [
            'name' => 'Beta Tester',
            'email' => 'beta@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'beta_code' => 'beta-test-123',
        ]);

        $response->assertRedirect(route('idea.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'beta@example.com']);
    }

    public function test_oauth_start_rejects_invalid_beta_access_code(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $response = $this->post(route('auth.google.start'), [
            'beta_code' => 'wrong-code',
        ]);

        $response->assertSessionHasErrors('beta_code');
        $this->assertFalse(session()->get(RegistrationGate::SESSION_KEY, false));
    }

    public function test_oauth_start_marks_session_verified_with_valid_beta_access_code(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $response = $this->post(route('auth.google.start'), [
            'beta_code' => 'beta-test-123',
        ]);

        $response->assertRedirect(route('auth.google'));
        $this->assertTrue(session()->get(RegistrationGate::SESSION_KEY, false));
    }

    public function test_google_callback_blocks_new_user_without_beta_session(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        Socialite::shouldReceive('driver->user')->andReturn((object) [
            'id' => 'google-new',
            'name' => 'New Google User',
            'email' => 'new-google@example.com',
        ]);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('register'));
        $response->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new-google@example.com']);
    }

    public function test_google_callback_creates_new_user_when_beta_session_verified(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        Socialite::shouldReceive('driver->user')->andReturn((object) [
            'id' => 'google-new',
            'name' => 'New Google User',
            'email' => 'new-google@example.com',
        ]);

        $response = $this->withSession([RegistrationGate::SESSION_KEY => true])
            ->get('/auth/google/callback');

        $response->assertRedirect(route('idea.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'new-google@example.com',
            'google_id' => 'google-new',
        ]);
        $this->assertFalse(session()->get(RegistrationGate::SESSION_KEY, false));
    }

    public function test_google_callback_logs_in_existing_user_without_beta_session(): void
    {
        config()->set('registration.beta_access_code', 'beta-test-123');

        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => 'google-existing',
        ]);

        Socialite::shouldReceive('driver->user')->andReturn((object) [
            'id' => 'google-existing',
            'name' => 'Existing Google User',
            'email' => 'existing@example.com',
        ]);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('idea.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_post_is_blocked_when_registration_is_closed(): void
    {
        config()->set('registration.enabled', false);

        $response = $this->post(route('register'), [
            'name' => 'Closed Beta',
            'email' => 'closed@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'closed@example.com']);
    }
}

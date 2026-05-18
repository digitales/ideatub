<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\AppearanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_store_appearance_in_session_and_database(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('settings.appearance.store'), [
            'appearance' => 'dark',
        ]);

        $response->assertNoContent();
        $this->assertSame('dark', session(AppearanceService::SESSION_KEY));
        $this->assertSame('dark', UserPreference::get($user, UserPreference::KEY_APPEARANCE));
    }

    public function test_invalid_appearance_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('settings.appearance.store'), [
            'appearance' => 'sepia',
        ]);

        $response->assertUnprocessable();
    }

    public function test_guest_cannot_store_appearance(): void
    {
        $response = $this->postJson(route('settings.appearance.store'), [
            'appearance' => 'dark',
        ]);

        $response->assertUnauthorized();
    }

    public function test_login_hydrates_appearance_from_user_preference(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_APPEARANCE, 'dark');

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('idea.index'));
        $this->assertSame('dark', session(AppearanceService::SESSION_KEY));
    }

    public function test_layout_renders_dark_class_when_session_is_dark(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([AppearanceService::SESSION_KEY => 'dark'])
            ->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('class="dark"', false);
        $response->assertSee('data-appearance="dark"', false);
    }

    public function test_profile_includes_appearance_control(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.profile.index'));

        $response->assertOk();
        $response->assertSee('data-appearance-control', false);
        $response->assertSee('Appearance', false);
    }
}

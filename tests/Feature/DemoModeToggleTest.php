<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_authenticated_user_can_enable_demo_mode_when_feature_is_enabled(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('demo-mode.enable'));

        $response->assertRedirect(route('idea.index'));
        $response->assertSessionHas('success');
        $response->assertSessionHas(DemoMode::ENABLED_SESSION_KEY, true);
        $response->assertSessionHas(DemoMode::SEED_SESSION_KEY);
    }

    public function test_guest_is_redirected_from_demo_mode_enable(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $response = $this->post(route('demo-mode.enable'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_demo_mode_disable(): void
    {
        config(['services.demo_mode.enabled' => true]);

        $response = $this->post(route('demo-mode.disable'));

        $response->assertRedirect(route('login'));
    }

    public function test_banner_renders_on_idea_index_when_demo_session_active(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();

        $page = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-123',
        ])
            ->actingAs($user)
            ->get(route('idea.index'));

        $page->assertOk();
        $page->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
    }

    public function test_demo_mode_routes_return_not_found_when_feature_disabled(): void
    {
        config(['services.demo_mode.enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('demo-mode.enable'))->assertNotFound();
        $this->actingAs($user)->post(route('demo-mode.disable'))->assertNotFound();
    }
}

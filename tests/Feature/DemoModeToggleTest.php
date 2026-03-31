<?php

namespace Tests\Feature;

use App\Models\Thought;
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

    public function test_after_enabling_demo_mode_idea_index_response_excludes_raw_thought_markers(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'DEMO_TOGGLE_REGRESSION_LEAK_MARKER_9c2e',
        ]);

        $normalPage = $this->actingAs($user)->get(route('idea.index'));
        $normalPage->assertOk();
        $normalPage->assertDontSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $normalPage->assertSee('DEMO_TOGGLE_REGRESSION_LEAK_MARKER_9c2e', false);
        $this->assertStringContainsString('DEMO_TOGGLE_REGRESSION_LEAK_MARKER_9c2e', $normalPage->getContent());

        $this->actingAs($user)->post(route('demo-mode.enable'));

        $page = $this->actingAs($user)->get(route('idea.index'));
        $page->assertOk();
        $page->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $page->assertDontSee('DEMO_TOGGLE_REGRESSION_LEAK_MARKER_9c2e', false);
        $this->assertStringNotContainsString('DEMO_TOGGLE_REGRESSION_LEAK_MARKER_9c2e', $page->getContent());
    }

    public function test_authenticated_user_can_disable_demo_mode_and_clear_session_state(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-123',
        ])->actingAs($user)->post(route('demo-mode.disable'));

        $response->assertRedirect(route('idea.index'));
        $response->assertSessionHas('success');
        $response->assertSessionMissing(DemoMode::ENABLED_SESSION_KEY);
        $response->assertSessionMissing(DemoMode::SEED_SESSION_KEY);
    }

    public function test_banner_does_not_render_when_feature_flag_is_disabled_even_with_stale_session_keys(): void
    {
        config(['services.demo_mode.enabled' => false]);
        $user = User::factory()->create();

        $page = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'seed-123',
        ])
            ->actingAs($user)
            ->get(route('idea.index'));

        $page->assertOk();
        $page->assertDontSee('Demo mode enabled. Sensitive text is obfuscated.', false);
    }

    public function test_feature_flag_off_with_stale_demo_session_does_not_obfuscate_idea_index_cards(): void
    {
        config(['services.demo_mode.enabled' => false]);
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'STALE_SESSION_INDEX_CARD_MARKER_a7f3',
        ]);

        $page = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'stale-seed',
        ])
            ->actingAs($user)
            ->get(route('idea.index'));

        $page->assertOk();
        $page->assertSee('STALE_SESSION_INDEX_CARD_MARKER_a7f3', false);
        $this->assertStringContainsString('STALE_SESSION_INDEX_CARD_MARKER_a7f3', $page->getContent());
    }

    public function test_demo_mode_routes_return_not_found_when_feature_disabled(): void
    {
        config(['services.demo_mode.enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('demo-mode.enable'))->assertNotFound();
        $this->actingAs($user)->post(route('demo-mode.disable'))->assertNotFound();
    }
}

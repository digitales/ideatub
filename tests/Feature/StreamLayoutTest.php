<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_store_layout_sets_session_and_returns_204(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'grid',
        ]);

        $response->assertNoContent();
        $this->assertEquals('grid', session('stream_layout'));
    }

    public function test_store_layout_rejects_invalid_value(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'masonry',
        ]);

        $response->assertUnprocessable();
    }

    public function test_store_layout_requires_auth(): void
    {
        $response = $this->postJson('/stream/layout', [
            'layout' => 'grid',
        ]);

        $response->assertUnauthorized();
    }

    public function test_store_layout_accepts_list_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/stream/layout', ['layout' => 'grid']);
        $this->assertEquals('grid', session('stream_layout'));

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'list',
        ]);

        $response->assertNoContent();
        $this->assertEquals('list', session('stream_layout'));
    }

    public function test_stream_page_defaults_to_list_layout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-layout="list"', false);
    }

    public function test_stream_page_renders_grid_when_session_set(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['stream_layout' => 'grid'])
            ->actingAs($user)
            ->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-layout="grid"', false);
    }

    public function test_stream_page_shows_layout_toggle_buttons(): void
    {
        $user = User::factory()->create();
        \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Toggle test thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-testid="layout-toggle-list"', false);
        $response->assertSee('data-testid="layout-toggle-grid"', false);
    }

    public function test_stream_toggle_not_shown_when_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertDontSee('data-testid="layout-toggle-list"', false);
    }

    public function test_stream_cards_include_grid_truncation_data_attribute(): void
    {
        $user = User::factory()->create();
        \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Truncation test thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-card', false);
    }
}

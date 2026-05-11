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
}

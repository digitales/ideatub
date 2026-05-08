<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['features.working_memory_ui' => true]);
    }

    public function test_tag_stream_shows_refresh_button_and_form_action(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'ai_notes']));

        $response->assertOk();
        $response->assertSee('/stream/tag/memory/refresh?tag=ai_notes&amp;signature=', false);
        $response->assertSee('name="tag" value="ai_notes"', false);
        $response->assertDontSee('name="active_tag"', false);
        $response->assertSee('Refresh working memory', false);
        $response->assertSee('data-working-memory-refresh', false);
        $response->assertSee('Open tag working memory', false);
        $response->assertSee(route('memory.tag.show', ['tag' => 'ai_notes']), false);
    }

    public function test_non_tag_stream_does_not_show_refresh_button(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertDontSee(route('working-memory.refresh.tag'), false);
        $response->assertDontSee('Refresh working memory', false);
    }

    public function test_stream_redirects_tag_query_with_spaces_to_underscore_slug(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('idea.stream', ['tag' => 'dark factory']))
            ->assertRedirect(route('idea.stream', ['tag' => 'dark_factory']));
    }
}

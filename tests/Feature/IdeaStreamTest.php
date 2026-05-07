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

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'ai-notes']));

        $response->assertOk();
        $response->assertSee('/stream/tag/memory/refresh?tag=ai-notes&amp;signature=', false);
        $response->assertSee('name="tag" value="ai-notes"', false);
        $response->assertDontSee('name="active_tag"', false);
        $response->assertSee('Refresh working memory', false);
        $response->assertSee('Open tag working memory', false);
        $response->assertSee(route('memory.tag.show', ['tag' => 'ai-notes']), false);
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
}

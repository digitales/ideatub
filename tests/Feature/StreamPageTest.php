<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Stream', false);
    }

    public function test_stream_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.stream'));

        $response->assertRedirect(route('login'));
    }

    public function test_stream_tag_filter_shows_only_matching_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Work thought',
            'metadata' => ['tags' => ['work']],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Personal thought',
            'metadata' => ['tags' => ['personal']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

        $response->assertStatus(200);
        $response->assertSee('Work thought');
        $response->assertDontSee('Personal thought');
    }

    public function test_stream_tag_filter_empty_shows_message(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Only work',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'nonexistent']));

        $response->assertStatus(200);
        $response->assertSee('No thoughts with tag');
        $response->assertSee('All thoughts', false);
    }

    public function test_stream_tag_slug_is_url_safe(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Web dev thought',
            'metadata' => ['tags' => ['web development']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'web_development']));

        $response->assertStatus(200);
        $response->assertSee('Web dev thought');
        $response->assertSee('web_development', false);
        $response->assertDontSee('web%20development', false);
    }

    public function test_stream_link_in_nav(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertStatus(200);
        $response->assertSee('Stream', false);
        $response->assertSee(route('idea.stream'), false);
    }
}

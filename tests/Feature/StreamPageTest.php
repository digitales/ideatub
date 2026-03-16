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

    public function test_stream_tag_filter_includes_parent_when_children_have_tag(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Doc intro',
            'metadata' => ['tags' => ['work']],
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'Section two content here',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

        $response->assertStatus(200);
        $response->assertSee('Doc intro');
        $response->assertSee('Section two content here');
    }

    public function test_stream_tag_filter_shows_parent_with_tag_only_in_children(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Document root',
            'metadata' => [], // root has no tag; only sections do
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'Section with doc tag',
            'metadata' => ['tags' => ['doc sections']],
        ]);

        // Slug for "doc sections" is doc_sections
        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'doc_sections']));

        $response->assertStatus(200);
        $response->assertSee('Document root');
        $response->assertSee('Section with doc tag');
    }

    public function test_stream_tag_view_shows_full_section_content(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Root',
            'metadata' => ['tags' => ['full doc']],
            'parent_id' => null,
        ]);
        $longSection = str_repeat('Paragraph content. ', 50);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => $longSection,
            'metadata' => ['tags' => ['full doc']],
        ]);

        // Slug for "full doc" is full_doc
        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'full_doc']));

        $response->assertStatus(200);
        $response->assertSee($longSection, false);
    }

    public function test_stream_excludes_jira_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira ticket PROJ-123',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Normal thought');
        $response->assertDontSee('Jira ticket PROJ-123');
    }

    public function test_jira_stream_shows_only_jira_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira ticket PROJ-123',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.jira'));

        $response->assertStatus(200);
        $response->assertSee('Jira');
        $response->assertSee('Jira ticket PROJ-123');
        $response->assertDontSee('Normal thought');
    }
}

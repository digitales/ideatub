<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Research doc',
        ]);

        $response = $this->get(route('idea.research.show', $thought));

        $response->assertRedirect(route('login'));
    }

    public function test_research_show_renders_formatted_markdown_for_owner(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Research title\n\nSome **bold** content.",
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('Research title', false);
        $response->assertSee('bold', false);
        $response->assertSee('Back to Stream', false);
        $response->assertSee('Research', false);
    }

    public function test_research_show_redirects_to_parent_when_viewing_child_thought(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Root research',
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'Section two',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $child));

        $response->assertRedirect(route('idea.research.show', $root));
    }

    public function test_research_show_returns_403_for_other_users_thought(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => 'Private research',
        ]);

        $response = $this->actingAs($other)->get(route('idea.research.show', $thought));

        $response->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchCommentsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_research_page_renders_page_level_comments_and_form(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Doc',
            'metadata' => ['type' => 'research'],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $user->id,
            'content' => 'my comment body',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));

        $response->assertStatus(200);
        $response->assertSee('my comment body', false);
        $response->assertSee('name="content"', false);
        $response->assertSee(route('comments.store'), false);
    }

    public function test_opening_research_page_marks_comments_read(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);

        $this->actingAs($user)->get(route('idea.research.show', $root))->assertOk();

        $this->assertDatabaseHas('thought_comment_reads', [
            'user_id' => $user->id,
            'thought_id' => $root->id,
        ]);
    }
}

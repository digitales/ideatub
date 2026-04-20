<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchSectionCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_section_comments_render_next_to_their_section(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'Section body',
            'source_metadata' => ['section_index' => 1],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $section->id,
            'author_user_id' => $user->id,
            'content' => 'section-level-body',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Section body', false);
        $response->assertSee('section-level-body', false);
        $response->assertSee('id="section-'.$section->id.'"', false);
    }
}

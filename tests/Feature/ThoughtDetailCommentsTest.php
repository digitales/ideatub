<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtDetailCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_thought_detail_renders_comments_from_new_system(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'root',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'new-system-comment',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));
        $response->assertOk();
        $response->assertSee('new-system-comment', false);
        $response->assertSee(route('comments.store'), false);
    }
}

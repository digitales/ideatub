<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnreadCommentIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_unread_count_renders_when_new_comments_arrive_after_last_visit(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);

        // Baseline: mark as read, then a guest leaves a comment.
        ThoughtCommentRead::markRead($owner->id, $root->id);
        $this->travel(1)->minutes();
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'new',
            'format' => 'plain',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));
        $response->assertOk();
        $response->assertSee('1 new comment', false);
    }

    public function test_owner_comments_do_not_inflate_unread_count(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);

        ThoughtCommentRead::markRead($owner->id, $root->id);
        $this->travel(1)->minutes();
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $owner->id,
            'content' => 'self',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));
        $response->assertOk();
        $response->assertDontSee('new comment', false);
    }
}

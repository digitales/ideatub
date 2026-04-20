<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_belongs_to_commentable_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'Hello',
            'format' => 'markdown',
        ]);

        $this->assertTrue($comment->commentable->is($thought));
        $this->assertTrue($comment->author->is($user));
        $this->assertFalse($comment->isGuest());
        $this->assertSame($user->name, $comment->displayName());
    }

    public function test_guest_comment_has_author_name_and_no_user(): void
    {
        $thought = Thought::factory()->create();

        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Guest Jane',
            'content' => 'Cool research',
            'format' => 'plain',
            'ip_hash' => str_repeat('a', 64),
        ]);

        $this->assertTrue($comment->isGuest());
        $this->assertSame('Guest Jane', $comment->displayName());
        $this->assertNull($comment->author);
    }

    public function test_has_comments_trait_exposes_morph_many(): void
    {
        $thought = Thought::factory()->create();
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $thought->user_id,
            'content' => 'a',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $thought->user_id,
            'content' => 'b',
            'format' => 'markdown',
        ]);

        $this->assertCount(2, $thought->comments()->get());
    }
}

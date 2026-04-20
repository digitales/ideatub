<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CommentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommentPolicy;
    }

    public function test_author_can_update_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = $this->ownerComment($user, $thought);

        $this->assertTrue($this->policy->update($user, $comment));
    }

    public function test_other_user_cannot_update_comment(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $author->id]);
        $comment = $this->ownerComment($author, $thought);

        $this->assertFalse($this->policy->update($other, $comment));
    }

    public function test_nobody_can_update_guest_comment(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'hi',
            'format' => 'plain',
        ]);

        $this->assertFalse($this->policy->update($owner, $comment));
    }

    public function test_author_can_delete_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = $this->ownerComment($user, $thought);

        $this->assertTrue($this->policy->delete($user, $comment));
    }

    public function test_commentable_owner_can_delete_any_comment_on_their_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'hi',
            'format' => 'plain',
        ]);

        $this->assertTrue($this->policy->delete($owner, $comment));
    }

    public function test_unrelated_user_cannot_delete_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = $this->ownerComment($owner, $thought);

        $this->assertFalse($this->policy->delete($other, $comment));
    }

    private function ownerComment(User $user, Thought $thought): Comment
    {
        return Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'hi',
            'format' => 'markdown',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_post_comment_on_own_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => 'My **markdown** reply',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'format' => 'markdown',
        ]);
    }

    public function test_non_owner_cannot_post_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => 'sneaky',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_unknown_morph_type_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'not_a_type',
            'commentable_id' => 'abc',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
    }

    public function test_author_can_update_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'orig',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->patch(route('comments.update', $comment), [
            'content' => 'edited',
        ]);

        $response->assertRedirect();
        $this->assertSame('edited', $comment->fresh()->content);
    }

    public function test_commentable_owner_can_delete_guest_comment(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'spam',
            'format' => 'plain',
        ]);

        $response = $this->actingAs($owner)->delete(route('comments.destroy', $comment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_content_too_long_returns_422(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => str_repeat('a', 10_001),
        ]);

        $response->assertStatus(422);
    }
}

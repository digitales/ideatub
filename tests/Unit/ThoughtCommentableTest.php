<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use App\Support\Comments\ShareContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtCommentableTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_comment_on_own_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($thought->authorizeCommentCreation($user, null));
        $this->assertSame($user->id, $thought->commentableOwnerId());
    }

    public function test_non_owner_cannot_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $this->assertFalse($thought->authorizeCommentCreation($other, null));
    }

    public function test_guest_can_comment_when_share_context_matches_and_allows(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $context = new ShareContext(
            researchThoughtId: $thought->id,
            shareId: 1,
            allowComments: true,
        );

        $this->assertTrue($thought->authorizeCommentCreation(null, $context));
    }

    public function test_guest_cannot_comment_when_share_disables_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $context = new ShareContext(
            researchThoughtId: $thought->id,
            shareId: 1,
            allowComments: false,
        );

        $this->assertFalse($thought->authorizeCommentCreation(null, $context));
    }

    public function test_guest_can_comment_on_section_child_of_shared_root(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $section = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $root->id,
            'source_metadata' => ['section_index' => 1],
        ]);

        $context = new ShareContext(
            researchThoughtId: $root->id,
            shareId: 1,
            allowComments: true,
        );

        $this->assertTrue($section->authorizeCommentCreation(null, $context));
    }
}

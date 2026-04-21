<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileResearchCommentUnreadCountsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_fixes_wrong_unread_count(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);

        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'x',
            'format' => 'plain',
        ]);

        ThoughtCommentRead::query()
            ->where('user_id', $owner->id)
            ->where('thought_id', $root->id)
            ->update(['unread_count' => 99]);

        $this->artisan('research:reconcile-comment-unread-counts')->assertExitCode(0);

        $row = ThoughtCommentRead::query()
            ->where('user_id', $owner->id)
            ->where('thought_id', $root->id)
            ->first();

        $this->assertSame(1, (int) $row->unread_count);
    }
}

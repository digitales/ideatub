<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtCommentReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_creates_or_updates_last_read_at(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        ThoughtCommentRead::markRead($user->id, $thought->id);

        $row = ThoughtCommentRead::where('user_id', $user->id)
            ->where('thought_id', $thought->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->last_read_at);

        $before = $row->last_read_at;
        $this->travel(1)->minutes();
        ThoughtCommentRead::markRead($user->id, $thought->id);
        $this->assertTrue($row->fresh()->last_read_at->greaterThan($before));
    }
}

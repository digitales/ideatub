<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ThoughtRepliesBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_shaped_children_backfilled_into_comments_and_hidden_from_queries(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'root',
        ]);
        $reply = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'a plain reply',
            'source_metadata' => null,
        ]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => '## Section body',
            'source_metadata' => ['section_index' => 1],
        ]);

        Artisan::call('comments:backfill-thought-replies');

        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'thought',
            'commentable_id' => $parent->id,
            'author_user_id' => $user->id,
            'content' => 'a plain reply',
            'format' => 'markdown',
            'import_source' => 'thought_reply_backfill',
        ]);

        $refreshedReply = Thought::withoutGlobalScope('non_migrated')->find($reply->id);
        $this->assertNotNull($refreshedReply);
        $this->assertSame(true, data_get($refreshedReply->metadata, 'migrated_to_comment'));

        // Default scope hides the migrated reply from normal queries.
        $this->assertNull(Thought::find($reply->id));
        // Section is NOT migrated.
        $this->assertNotNull(Thought::find($section->id));
    }

    public function test_microsite_child_pages_are_not_backfilled_or_hidden(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Index',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => 'index',
                'import_order' => 0,
            ],
        ]);
        $page = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Executive summary',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => '01-executive-summary',
                'import_order' => 1,
                'microsite_root_id' => (string) $root->id,
            ],
        ]);

        Artisan::call('comments:backfill-thought-replies');

        $this->assertDatabaseMissing('comments', [
            'commentable_id' => $root->id,
            'content' => '# Executive summary',
            'import_source' => 'thought_reply_backfill',
        ]);
        $this->assertNotNull(Thought::find($page->id));
        $this->assertNotSame(true, data_get(Thought::find($page->id)?->metadata, 'migrated_to_comment'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MicrositeReplyBackfillRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_migration_restores_microsite_children_hidden_by_reply_backfill(): void
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
            'metadata' => [
                'type' => 'research',
                'migrated_to_comment' => true,
            ],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'page_path_segment' => '01-executive-summary',
                'import_order' => 1,
                'microsite_root_id' => (string) $root->id,
            ],
        ]);

        $this->assertNull(Thought::find($page->id));

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_06_10_120000_restore_microsite_pages_after_reply_backfill.php',
            '--force' => true,
        ]);

        $restored = Thought::find($page->id);
        $this->assertNotNull($restored);
        $this->assertNotSame(true, data_get($restored->metadata, 'migrated_to_comment'));

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));
        $response->assertOk();
        $response->assertSee('Executive summary', false);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reply backfill (2026_04_20_000004) treated microsite child pages as reply-shaped
 * thoughts because they use import_order, not section_index. Restore visibility and
 * remove comments that were copied from microsite pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->upPgsql();
        } else {
            $this->upGeneric();
        }
    }

    private function upPgsql(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM comments c
            USING thoughts child, thoughts root
            WHERE c.import_source = 'thought_reply_backfill'
              AND child.parent_id = root.id
              AND child.source_metadata->>'document_layout' = 'microsite'
              AND root.source_metadata->>'document_layout' = 'microsite'
              AND root.parent_id IS NULL
              AND c.commentable_id = root.id::text
              AND c.author_user_id = child.user_id
              AND c.content = child.content
              AND c.created_at = child.created_at
        SQL);

        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = ((metadata::jsonb) - 'migrated_to_comment')::json
            WHERE parent_id IS NOT NULL
              AND source_metadata->>'document_layout' = 'microsite'
              AND metadata->>'migrated_to_comment' = 'true'
        SQL);
    }

    private function upGeneric(): void
    {
        $pages = DB::table('thoughts')
            ->whereNotNull('parent_id')
            ->where('source_metadata->document_layout', 'microsite')
            ->get(['id', 'parent_id', 'user_id', 'content', 'created_at', 'metadata']);

        foreach ($pages as $page) {
            DB::table('comments')
                ->where('import_source', 'thought_reply_backfill')
                ->where('commentable_id', (string) $page->parent_id)
                ->where('author_user_id', $page->user_id)
                ->where('content', $page->content)
                ->where('created_at', $page->created_at)
                ->delete();

            $meta = json_decode((string) ($page->metadata ?? '{}'), true);
            if (! is_array($meta) || ! ($meta['migrated_to_comment'] ?? false)) {
                continue;
            }
            unset($meta['migrated_to_comment']);
            DB::table('thoughts')
                ->where('id', $page->id)
                ->update(['metadata' => json_encode($meta)]);
        }
    }

    public function down(): void
    {
        // Data repair is not safely reversible.
    }
};

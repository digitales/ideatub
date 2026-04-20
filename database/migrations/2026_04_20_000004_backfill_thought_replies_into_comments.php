<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO comments (
                commentable_type, commentable_id, author_user_id, author_name,
                content, format, ip_hash, import_source, created_at, updated_at
            )
            SELECT 'thought', t.parent_id, t.user_id, NULL,
                t.content, 'markdown', NULL, 'thought_reply_backfill',
                t.created_at, t.updated_at
            FROM thoughts t
            WHERE t.parent_id IS NOT NULL
              AND (t.source_metadata IS NULL OR t.source_metadata->>'section_index' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'video_section_type' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);

        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = COALESCE(metadata, '{}'::jsonb) || '{"migrated_to_comment": true}'::jsonb
            WHERE parent_id IS NOT NULL
              AND (source_metadata IS NULL OR source_metadata->>'section_index' IS NULL)
              AND (metadata IS NULL OR metadata->>'video_section_type' IS NULL)
              AND (metadata IS NULL OR metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);
    }

    public function down(): void
    {
        DB::table('comments')->where('import_source', 'thought_reply_backfill')->delete();
        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = metadata - 'migrated_to_comment'
            WHERE metadata->>'migrated_to_comment' = 'true'
        SQL);
    }
};

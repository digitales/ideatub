<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillThoughtRepliesCommand extends Command
{
    protected $signature = 'comments:backfill-thought-replies';

    protected $description = 'Copy existing reply-shaped child thoughts into the comments table.';

    public function handle(): int
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
              AND (t.source_metadata IS NULL OR t.source_metadata->>'document_layout' IS DISTINCT FROM 'microsite')
              AND (t.metadata IS NULL OR t.metadata->>'video_section_type' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
              AND NOT EXISTS (
                  SELECT 1 FROM comments c
                  WHERE c.import_source = 'thought_reply_backfill'
                    AND c.commentable_id = t.parent_id::text
                    AND c.author_user_id = t.user_id
                    AND c.created_at = t.created_at
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = (
                COALESCE(metadata::jsonb, '{}'::jsonb) || '{"migrated_to_comment": true}'::jsonb
            )::json
            WHERE parent_id IS NOT NULL
              AND (source_metadata IS NULL OR source_metadata->>'section_index' IS NULL)
              AND (source_metadata IS NULL OR source_metadata->>'document_layout' IS DISTINCT FROM 'microsite')
              AND (metadata IS NULL OR metadata->>'video_section_type' IS NULL)
              AND (metadata IS NULL OR metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}

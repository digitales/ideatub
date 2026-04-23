<?php

namespace App\Console\Commands;

use App\Services\Comments\ResearchCommentUnreadService;
use Illuminate\Console\Command;

class ReconcileResearchCommentUnreadCountsCommand extends Command
{
    protected $signature = 'research:reconcile-comment-unread-counts';

    protected $description = 'Recompute stored research unread_comment counts from comments (fixes drift)';

    public function handle(ResearchCommentUnreadService $service): int
    {
        $updated = $service->reconcileStoredCounts();
        $this->info("Updated {$updated} thought_comment_reads row(s).");

        return self::SUCCESS;
    }
}

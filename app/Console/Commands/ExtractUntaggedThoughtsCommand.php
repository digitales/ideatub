<?php

namespace App\Console\Commands;

use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Console\Command;

class ExtractUntaggedThoughtsCommand extends Command
{
    protected $signature = 'thoughts:extract-untagged
                            {--limit=50 : Max number of thoughts to process per run}
                            {--dry-run : List thoughts that would be processed without updating}';

    protected $description = 'Extract metadata (tags, type, people, action_items) for thoughts that have no tags. Safe to run periodically; never removes existing tags.';

    public function handle(OpenRouterService $openRouter): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $thoughts = Thought::query()
            ->withoutTags()
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($thoughts->isEmpty()) {
            $this->info('No thoughts without tags found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d thought(s) without tags.', $thoughts->count()));

        if ($dryRun) {
            foreach ($thoughts as $thought) {
                $this->line('  '.$thought->id.' ('.$thought->created_at->toDateString().')');
            }
            $this->comment('Dry run: no changes made. Run without --dry-run to extract metadata.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($thoughts as $thought) {
            // Re-check: only update if still no tags (avoid race with another process)
            $fresh = $thought->fresh();
            $tags = $fresh->metadata['tags'] ?? null;
            if (is_array($tags) && count($tags) > 0) {
                continue;
            }

            try {
                $metadata = Thought::normalizeMetadataTags($openRouter->extractMetadata($thought->content));
                $thought->update(['metadata' => $metadata]);
                $processed++;
            } catch (\Throwable $e) {
                report($e);
                $this->warn(sprintf('  Failed %s: %s', $thought->id, $e->getMessage()));
                $failed++;
            }
        }

        $this->info(sprintf('Processed: %d, failed: %d.', $processed, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

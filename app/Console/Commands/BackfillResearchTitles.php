<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use League\CommonMark\CommonMarkConverter;

class BackfillResearchTitles extends Command
{
    protected $signature = 'research:backfill-titles
                            {--user= : Scope to a specific user ID}
                            {--dry-run : Preview without saving}';

    protected $description = 'Extract and set titles for existing research thoughts that lack one';

    public function handle(): int
    {
        $query = Thought::query()
            ->whereNull('parent_id')
            ->where('metadata->type', 'research');

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $query->whereRaw("metadata->>'title' IS NULL");
        } else {
            $query->whereRaw("json_extract(metadata, '$.title') IS NULL");
        }

        if ($userId = $this->option('user')) {
            $query->where('user_id', (int) $userId);
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        $query->chunkById(100, function ($thoughts) use ($dryRun, &$updated, &$skipped) {
            foreach ($thoughts as $thought) {
                $title = $this->extractTitle($thought->content);

                if ($title === null) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] {$thought->id}: \"{$title}\"");
                    $updated++;
                    continue;
                }

                $metadata = $thought->metadata ?? [];
                $metadata['title'] = $title;
                $thought->update(['metadata' => $metadata]);
                $updated++;
            }
        });

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function extractTitle(string $content): ?string
    {
        if (preg_match('/^#{1,3}\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);

            return mb_substr($title, 0, 255);
        }

        $plain = strip_tags((new CommonMarkConverter)->convert($content)->getContent());
        $plain = preg_replace('/\s+/', ' ', trim($plain));

        if ($plain === '') {
            return null;
        }

        if (mb_strlen($plain) <= 80) {
            return $plain;
        }

        $truncated = mb_substr($plain, 0, 80);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 40) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated;
    }
}

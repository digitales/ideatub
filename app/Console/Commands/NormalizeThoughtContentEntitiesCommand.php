<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;

class NormalizeThoughtContentEntitiesCommand extends Command
{
    protected $signature = 'thoughts:normalize-content-entities
                            {--dry-run : Show what would be updated without writing}
                            {--limit= : Max number of thoughts to process (default: all)}';

    protected $description = 'Decode HTML entities in existing thought content so stored text is plain (e.g. &#039; → \'). One-off after adding content mutator.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = Thought::query()->whereNotNull('content')->orderBy('id');

        $updated = 0;
        $chunkSize = 500;

        $query->when($limit !== null, fn ($q) => $q->limit($limit))
            ->chunk($chunkSize, function ($thoughts) use ($dryRun, &$updated) {
                foreach ($thoughts as $thought) {
                    $raw = $thought->getRawContent();
                    $decoded = Thought::decodeContentEntities($raw);
                    if ($decoded === $raw) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf('  %s: %s… → %s…', $thought->id, mb_substr($raw, 0, 40), mb_substr($decoded, 0, 40)));
                        $updated++;
                        continue;
                    }

                    $thought->update(['content' => $decoded]);
                    $updated++;
                }
            });

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d thought(s) would be updated. Run without --dry-run to apply.', $updated));
        } else {
            $this->info(sprintf('Updated %d thought(s).', $updated));
        }

        return self::SUCCESS;
    }
}

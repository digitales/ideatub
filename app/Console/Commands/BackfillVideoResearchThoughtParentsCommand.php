<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;

class BackfillVideoResearchThoughtParentsCommand extends Command
{
    protected $signature = 'video-research:backfill-parents {--dry-run : List changes without writing}';

    protected $description = 'Set parent_id on legacy top-level video research thoughts (metadata video_thought_id) so they nest under the video root and disappear from the main stream.';

    private int $scanned = 0;

    private int $updated = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        Thought::query()
            ->whereNull('parent_id')
            ->matchingCanonicalMetadataType('research')
            ->where(function ($q): void {
                $q->whereNotNull('metadata->video_thought_id')
                    ->orWhereNotNull('source_metadata->video_thought_id');
            })
            ->orderBy('created_at')
            ->each(function (Thought $research) use ($dryRun): void {
                $this->scanned++;
                $this->processResearch($research, $dryRun);
            });

        $this->line('Scanned: '.$this->scanned);
        $this->line('Updated: '.$this->updated);
        $this->line('Skipped: '.$this->skipped);

        if ($dryRun) {
            $this->comment('Dry run: no database writes were performed.');
        }

        return self::SUCCESS;
    }

    private function processResearch(Thought $research, bool $dryRun): void
    {
        $videoThoughtId = $this->resolveVideoThoughtId($research);
        if ($videoThoughtId === null) {
            $this->skipped++;

            return;
        }

        $video = Thought::query()
            ->whereKey($videoThoughtId)
            ->where('user_id', $research->user_id)
            ->first();

        if ($video === null || data_get($video->metadata, 'type') !== 'video') {
            $this->skipped++;

            return;
        }

        if ((string) $research->parent_id === (string) $video->id) {
            $this->skipped++;

            return;
        }

        if ($dryRun) {
            $this->updated++;

            return;
        }

        $meta = is_array($research->metadata) ? $research->metadata : [];
        $meta['video_section_type'] = 'research';
        if (! isset($meta['video_thought_id']) || $meta['video_thought_id'] === null || $meta['video_thought_id'] === '') {
            $meta['video_thought_id'] = $video->id;
        }

        $research->update([
            'parent_id' => $video->id,
            'metadata' => Thought::normalizeMetadataTags($meta),
        ]);

        $this->updated++;
    }

    private function resolveVideoThoughtId(Thought $research): ?string
    {
        foreach ([
            data_get($research->metadata, 'video_thought_id'),
            data_get($research->source_metadata, 'video_thought_id'),
        ] as $id) {
            if (is_string($id) && trim($id) !== '') {
                return trim($id);
            }
        }

        return null;
    }
}

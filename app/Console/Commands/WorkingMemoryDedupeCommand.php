<?php

namespace App\Console\Commands;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryContentFingerprint;
use App\Services\WorkingMemory\WorkingMemoryDedupeFamilyResolver;
use App\Services\WorkingMemory\WorkingMemorySnapshotSuperseder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WorkingMemoryDedupeCommand extends Command
{
    protected $signature = 'working-memory:dedupe
        {--days=30 : Only process captures within this many days}
        {--dry-run : Report actions without writing}
        {--user= : Limit to a single user id}';

    protected $description = 'Backfill working-memory fingerprints and supersede duplicate Stream snapshots and external versions';

    public function handle(
        WorkingMemoryContentFingerprint $fingerprint,
        WorkingMemoryDedupeFamilyResolver $familyResolver,
        WorkingMemorySnapshotSuperseder $snapshotSuperseder,
    ): int {
        if (! config('working_memory.dedupe_enabled', true)) {
            $this->warn('Working memory dedupe is disabled (WORKING_MEMORY_DEDUPE_ENABLED).');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $since = now()->subDays($days);
        $thoughtsSuperseded = 0;
        $versionsSuperseded = 0;

        $thoughtQuery = Thought::query()
            ->where('created_at', '>=', $since)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        $wmThoughts = $thoughtQuery
            ->get()
            ->filter(fn (Thought $t): bool => $this->isWorkingMemorySnapshot($t));

        $this->info(sprintf(
            'Found %d working-memory snapshot thoughts since %s.',
            $wmThoughts->count(),
            $since->toDateString(),
        ));

        foreach ($wmThoughts as $thought) {
            if ($thought->content_fingerprint === null) {
                $hash = $fingerprint->hash($thought->content ?? '');
                if (! $dryRun) {
                    $thought->forceFill(['content_fingerprint' => $hash])->save();
                }
            }
        }

        $byFamily = $wmThoughts->groupBy(fn (Thought $t): string => $this->resolveThoughtFamily($t, $familyResolver));

        foreach ($byFamily as $family => $group) {
            if ($family === '' || $family === 'unknown') {
                continue;
            }

            $byFingerprint = $group->groupBy(fn (Thought $t): string => (string) ($t->content_fingerprint ?? 'unknown'));

            foreach ($byFingerprint as $fingerprintKey => $cluster) {
                if ($fingerprintKey === 'unknown' || $cluster->count() < 2) {
                    continue;
                }

                $sorted = $cluster->sortByDesc(fn (Thought $t) => $t->created_at?->timestamp ?? 0)->values();
                $winner = $sorted->first();
                $losers = $sorted->slice(1);

                foreach ($losers as $loser) {
                    $this->line("  [thought] supersede {$loser->id} → winner {$winner->id} ({$family})");
                    if (! $dryRun && $winner instanceof Thought) {
                        $snapshotSuperseder->supersede($loser, $winner);
                        $thoughtsSuperseded++;
                    } elseif ($dryRun) {
                        $thoughtsSuperseded++;
                    }
                }
            }
        }

        $memoryQuery = WorkingMemory::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->with(['versions' => fn ($q) => $q->where('build_type', 'external')->where('created_at', '>=', $since)]);

        foreach ($memoryQuery->cursor() as $memory) {
            $externals = $memory->versions;
            foreach ($externals as $version) {
                if ($version->content_fingerprint === null && is_string($version->summary_markdown)) {
                    $hash = $fingerprint->hash($version->summary_markdown);
                    if (! $dryRun) {
                        $version->forceFill(['content_fingerprint' => $hash])->save();
                    }
                }
            }

            $grouped = $externals->groupBy('content_fingerprint');
            foreach ($grouped as $fp => $cluster) {
                if ($fp === null || $fp === '' || $cluster->count() < 2) {
                    continue;
                }

                $sorted = $cluster->sortByDesc('created_at')->values();
                $winner = $sorted->first();
                foreach ($sorted->slice(1) as $loser) {
                    $this->line("  [version] supersede {$loser->id} → winner {$winner->id}");
                    if (! $dryRun && $winner instanceof WorkingMemoryVersion) {
                        $loser->forceFill([
                            'superseded_at' => now(),
                            'superseded_by_version_id' => $winner->id,
                        ])->save();
                        $versionsSuperseded++;
                    } elseif ($dryRun) {
                        $versionsSuperseded++;
                    }
                }
            }

            if (! $dryRun) {
                $latest = $memory->versions()
                    ->where('build_type', 'external')
                    ->whereNull('superseded_at')
                    ->orderByDesc('created_at')
                    ->first();
                if ($latest instanceof WorkingMemoryVersion) {
                    $memory->forceFill(['latest_version_id' => $latest->id])->save();
                }
            }
        }

        $this->info(sprintf(
            '%s %d duplicate thoughts and %d duplicate versions.',
            $dryRun ? 'Would supersede' : 'Superseded',
            $thoughtsSuperseded,
            $versionsSuperseded,
        ));

        return self::SUCCESS;
    }

    private function isWorkingMemorySnapshot(Thought $thought): bool
    {
        $tags = collect(data_get($thought->metadata, 'tags', []))
            ->map(fn ($t) => Str::lower((string) $t));

        if ($tags->contains('working-memory')) {
            return true;
        }

        $planSlug = Str::lower((string) data_get($thought->source_metadata, 'plan_slug', ''));

        return preg_match('/^(client|project)-working-memory/i', $planSlug) === 1;
    }

    private function resolveThoughtFamily(Thought $thought, WorkingMemoryDedupeFamilyResolver $resolver): string
    {
        $existing = data_get($thought->source_metadata, 'working_memory.dedupe_family');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $tags = collect(data_get($thought->metadata, 'tags', []))
            ->map(fn ($t) => (string) $t)
            ->all();

        return $resolver->resolveForCapture(
            planSlug: data_get($thought->source_metadata, 'plan_slug'),
            extraTags: $tags,
            project: data_get($thought->source_metadata, 'project'),
        );
    }
}

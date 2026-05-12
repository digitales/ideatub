<?php

namespace App\Console\Commands;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WorkingMemoryCleanupCommand extends Command
{
    protected $signature = 'working-memory:cleanup
        {--scope-type=project : Scope type to clean}
        {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Remove stale working memory records that have no external or validated version';

    public function handle(): int
    {
        $scopeType = (string) $this->option('scope-type');
        $dryRun = (bool) $this->option('dry-run');

        $memories = WorkingMemory::query()
            ->where('scope_type', $scopeType)
            ->with('latestVersion')
            ->get();

        $this->info("Found {$memories->count()} {$scopeType}-scoped working memory records.");

        $toKeep = [];
        $toRemove = [];

        foreach ($memories as $memory) {
            $hasExternal = $memory->versions()
                ->where('build_type', 'external')
                ->exists();

            $hasValidated = $memory->versions()
                ->where('authoring_status', 'validated')
                ->exists();

            $buildType = $memory->latestVersion?->build_type ?? 'none';
            $label = "{$memory->scope_key} (latest: {$buildType}, versions: {$memory->versions()->count()})";

            if ($hasExternal || $hasValidated) {
                $toKeep[] = $label;
            } else {
                $toRemove[] = ['memory' => $memory, 'label' => $label];
            }
        }

        if ($toKeep) {
            $this->info("\nKeeping (".count($toKeep).'):');
            foreach ($toKeep as $label) {
                $this->line("  ✓ {$label}");
            }
        }

        if ($toRemove) {
            $this->warn("\n".($dryRun ? 'Would remove' : 'Removing').' ('.count($toRemove).'):');
            foreach ($toRemove as $item) {
                $this->line("  ✗ {$item['label']}");
            }

            if (! $dryRun) {
                DB::transaction(function () use ($toRemove): void {
                    foreach ($toRemove as $item) {
                        $memory = $item['memory'];
                        WorkingMemoryVersion::where('working_memory_id', $memory->id)
                            ->each(function (WorkingMemoryVersion $version): void {
                                $version->inputs()->delete();
                                $version->delete();
                            });
                        $memory->delete();
                    }
                });
                $this->info("\nDeleted ".count($toRemove).' stale records.');
            }
        } else {
            $this->info("\nNo stale records to remove.");
        }

        return self::SUCCESS;
    }
}

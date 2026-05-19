<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\WorkingMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MigrateWorkingMemoryProjectScopeKeysCommand extends Command
{
    protected $signature = 'working-memory:migrate-project-scope-keys
        {--user= : Limit to a single user id}
        {--dry-run : Log planned updates without writing}';

    protected $description = 'Migrate legacy project working-memory scope_key slugs (e.g. dezeen/foo) to project UUIDs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null && $this->option('user') !== ''
            ? (int) $this->option('user')
            : null;

        if ($userId !== null && $userId < 1) {
            $this->error('--user must be a positive integer.');

            return self::FAILURE;
        }

        $query = WorkingMemory::query()
            ->where('scope_type', 'project')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        $candidates = $query->get()->filter(
            fn (WorkingMemory $memory): bool => ! Str::isUuid((string) $memory->scope_key),
        );

        $this->info(sprintf(
            'Found %d project-scoped working memories with non-UUID scope_key%s.',
            $candidates->count(),
            $dryRun ? ' (dry-run)' : '',
        ));

        $migrated = 0;
        $skipped = 0;

        foreach ($candidates as $memory) {
            $legacyKey = Str::lower(trim((string) $memory->scope_key));
            $parsed = $this->parseLegacyScopeKey($legacyKey);

            if ($parsed === null) {
                $this->warn("  skip {$memory->id}: unparseable scope_key \"{$legacyKey}\"");
                $skipped++;

                continue;
            }

            $project = $this->findProjectForSlugs(
                (int) $memory->user_id,
                $parsed['client_slug'],
                $parsed['project_slug'],
            );

            if ($project === null) {
                $this->warn("  skip {$memory->id}: no project for \"{$legacyKey}\" (user {$memory->user_id})");
                $skipped++;

                continue;
            }

            $targetKey = (string) $project->getKey();

            if ($targetKey === $legacyKey) {
                continue;
            }

            $conflict = WorkingMemory::query()
                ->where('user_id', $memory->user_id)
                ->where('scope_type', 'project')
                ->where('scope_key', $targetKey)
                ->where('id', '!=', $memory->id)
                ->exists();

            if ($conflict) {
                $this->warn("  skip {$memory->id}: target scope {$targetKey} already exists for user {$memory->user_id}");
                $skipped++;

                continue;
            }

            $this->line("  migrate {$memory->id}: \"{$legacyKey}\" → {$targetKey}");

            if (! $dryRun) {
                $memory->forceFill(['scope_key' => $targetKey])->save();
            }

            $migrated++;
        }

        $this->info(sprintf(
            '%s %d scope keys; skipped %d.',
            $dryRun ? 'Would migrate' : 'Migrated',
            $migrated,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{client_slug: string, project_slug: ?string}|null
     */
    private function parseLegacyScopeKey(string $scopeKey): ?array
    {
        if ($scopeKey === '') {
            return null;
        }

        $parts = array_values(array_filter(
            explode('/', $scopeKey),
            fn (string $part): bool => $part !== '',
        ));

        if ($parts === [] || count($parts) > 2) {
            return null;
        }

        $clientSlug = Str::lower(trim($parts[0]));
        if ($clientSlug === '' || ! preg_match('/^[a-z0-9-]+$/', $clientSlug)) {
            return null;
        }

        if (count($parts) === 1) {
            return [
                'client_slug' => $clientSlug,
                'project_slug' => null,
            ];
        }

        $projectSlug = Str::lower(trim($parts[1]));
        if ($projectSlug === '' || ! preg_match('/^[a-z0-9-]+$/', $projectSlug)) {
            return null;
        }

        return [
            'client_slug' => $clientSlug,
            'project_slug' => $projectSlug,
        ];
    }

    private function findProjectForSlugs(int $userId, string $clientSlug, ?string $projectSlug): ?Project
    {
        $query = Project::query()
            ->where('user_id', $userId)
            ->where('elixirr_client_slug', $clientSlug);

        if ($projectSlug === null) {
            return $query
                ->whereNull('elixirr_project_slug')
                ->first();
        }

        return $query
            ->where('elixirr_project_slug', $projectSlug)
            ->first();
    }
}

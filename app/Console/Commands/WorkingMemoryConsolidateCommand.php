<?php

namespace App\Console\Commands;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkingMemoryConsolidateCommand extends Command
{
    protected $signature = 'working-memory:consolidate {--user=} {--scope_type=} {--scope_key=}';

    protected $description = 'Consolidate working memory snapshots for users and scopes.';

    public function handle(): int
    {
        $scopeTypeOption = $this->option('scope_type');
        $scopeKeyOption = $this->option('scope_key');

        if (($scopeTypeOption === null) xor ($scopeKeyOption === null)) {
            $this->error('Both --scope_type and --scope_key must be supplied together.');

            return self::FAILURE;
        }

        try {
            $userIds = $this->resolveUserIds($this->option('user'));

            foreach ($userIds as $userId) {
                foreach ($this->resolveScopesForUser($userId, $scopeTypeOption, $scopeKeyOption) as $scope) {
                    ConsolidateWorkingMemory::dispatch(
                        $userId,
                        $scope['scope_type'],
                        $scope['scope_key']
                    );
                }
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Working memory consolidation complete.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveUserIds(mixed $userOption): array
    {
        if ($userOption !== null) {
            if (! is_string($userOption) || trim($userOption) === '' || ! ctype_digit(trim($userOption))) {
                throw new InvalidArgumentException('The --user option must be a numeric user id.');
            }

            $userId = (int) trim($userOption);
            if (! User::query()->whereKey($userId)->exists()) {
                throw new InvalidArgumentException("User {$userId} does not exist.");
            }

            return [$userId];
        }

        return User::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<array{scope_type: string, scope_key: string}>
     */
    private function resolveScopesForUser(int $userId, mixed $scopeTypeOption, mixed $scopeKeyOption): array
    {
        if (is_string($scopeTypeOption) && is_string($scopeKeyOption)) {
            [$scopeType, $scopeKey] = $this->normalizeScope($scopeTypeOption, $scopeKeyOption);

            return [['scope_type' => $scopeType, 'scope_key' => $scopeKey]];
        }

        $scopes = collect([
            ['scope_type' => 'global', 'scope_key' => 'global'],
        ]);

        Thought::query()
            ->where('user_id', $userId)
            ->with('projects:id')
            ->orderByDesc('created_at')
            ->get()
            ->each(function (Thought $thought) use ($scopes): void {
                $metadataProject = Str::of((string) data_get($thought->source_metadata, 'project'))
                    ->trim()
                    ->lower()
                    ->toString();

                if ($metadataProject !== '') {
                    $scopes->push([
                        'scope_type' => 'project',
                        'scope_key' => $metadataProject,
                    ]);
                }

                foreach ($thought->projects as $project) {
                    $scopes->push([
                        'scope_type' => 'project',
                        'scope_key' => (string) $project->id,
                    ]);
                }
            });

        return $this->uniqueScopes($scopes);
    }

    /**
     * @param  Collection<int, array{scope_type: string, scope_key: string}>  $scopes
     * @return list<array{scope_type: string, scope_key: string}>
     */
    private function uniqueScopes(Collection $scopes): array
    {
        return $scopes
            ->unique(fn (array $scope): string => $scope['scope_type'].'|'.$scope['scope_key'])
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeScope(string $scopeType, string $scopeKey): array
    {
        $normalizedScopeType = Str::of($scopeType)->trim()->toString();
        $normalizedScopeKey = Str::of($scopeKey)->trim()->toString();

        if (! in_array($normalizedScopeType, ['global', 'project'], true)) {
            throw new InvalidArgumentException('Invalid --scope_type. Allowed values: global, project.');
        }

        if ($normalizedScopeKey === '') {
            throw new InvalidArgumentException('Invalid --scope_key. --scope_key must not be empty.');
        }

        if ($normalizedScopeType === 'global') {
            if ($normalizedScopeKey !== 'global') {
                throw new InvalidArgumentException("Invalid --scope_key for global scope. --scope_key must be exactly 'global'.");
            }

            return ['global', 'global'];
        }

        return ['project', Str::of($normalizedScopeKey)->lower()->toString()];
    }
}

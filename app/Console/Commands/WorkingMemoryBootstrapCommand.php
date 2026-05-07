<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\ConsolidateWorkingMemory;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class WorkingMemoryBootstrapCommand extends Command
{
    protected $signature = 'working-memory:bootstrap {scope_type} {scope_key} {--user=}';

    protected $description = 'Backfill all compactions for a scope, then trigger a consolidated authoring pass.';

    public function handle(): int
    {
        try {
            $userId = $this->resolveUserId();
            [$scopeType, $scopeKey] = $this->resolveScope();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $meetings = Thought::query()
            ->where('user_id', $userId)
            ->whereJsonContains('metadata->type', 'meeting')
            ->orderBy('created_at')
            ->get();

        $this->info(sprintf('Bootstrapping %d meeting compaction(s) for %s/%s...', $meetings->count(), $scopeType, $scopeKey));
        foreach ($meetings as $meeting) {
            SynthesizeMeetingCompactionJob::dispatchSync((string) $meeting->id);
        }

        $this->info('Building scope digest...');
        BuildScopeDigestJob::dispatchSync($userId, $scopeType, $scopeKey);

        $this->info('Building research synthesis...');
        SynthesizeResearchCompactionJob::dispatchSync($userId, $scopeType, $scopeKey);

        $this->info('Triggering consolidated authoring...');
        ConsolidateWorkingMemory::dispatch($userId, $scopeType, $scopeKey);

        $this->info('Bootstrap complete.');

        return self::SUCCESS;
    }

    private function resolveUserId(): int
    {
        $userIdOption = $this->option('user');
        if (! is_string($userIdOption) || trim($userIdOption) === '' || ! ctype_digit(trim($userIdOption))) {
            throw new InvalidArgumentException('--user is required and must be a numeric user id.');
        }

        $userId = (int) trim($userIdOption);
        if (! User::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException("User {$userId} does not exist.");
        }

        return $userId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveScope(): array
    {
        $scopeType = (string) $this->argument('scope_type');
        $scopeKey = (string) $this->argument('scope_key');

        if (! in_array($scopeType, ['global', 'project', 'insights', 'tag'], true)) {
            throw new InvalidArgumentException('Invalid scope_type. Allowed: global, project, insights, tag.');
        }

        if (trim($scopeKey) === '') {
            throw new InvalidArgumentException('scope_key must not be empty.');
        }

        if (in_array($scopeType, ['global', 'insights'], true) && $scopeKey !== 'global') {
            throw new InvalidArgumentException("scope_key for {$scopeType} must be 'global'.");
        }

        return [$scopeType, $scopeType === 'project' || $scopeType === 'tag' ? strtolower(trim($scopeKey)) : $scopeKey];
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CompactionsRebuildCommand extends Command
{
    protected $signature = 'compactions:rebuild {scope_type} {scope_key} {--user=} {--type=}';

    protected $description = 'Manually rebuild a single compaction subtype for a scope.';

    private const ALLOWED_TYPES = ['meeting', 'weekly-digest', 'research-synth'];

    public function handle(): int
    {
        try {
            $userId = $this->resolveUserId();
            [$scopeType, $scopeKey] = $this->resolveScope();
            $type = $this->resolveType();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        switch ($type) {
            case 'meeting':
                $meetings = Thought::query()
                    ->where('user_id', $userId)
                    ->whereJsonContains('metadata->type', 'meeting')
                    ->orderBy('created_at')
                    ->get();
                foreach ($meetings as $meeting) {
                    SynthesizeMeetingCompactionJob::dispatchSync((string) $meeting->id);
                }
                $this->info(sprintf('Rebuilt %d meeting compaction(s).', $meetings->count()));
                break;

            case 'weekly-digest':
                BuildScopeDigestJob::dispatchSync($userId, $scopeType, $scopeKey);
                $this->info('Rebuilt weekly digest.');
                break;

            case 'research-synth':
                SynthesizeResearchCompactionJob::dispatchSync($userId, $scopeType, $scopeKey);
                $this->info('Rebuilt research synthesis.');
                break;
        }

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

    private function resolveType(): string
    {
        $type = (string) ($this->option('type') ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid --type. Allowed values: '.implode(', ', self::ALLOWED_TYPES)
            );
        }

        return $type;
    }
}

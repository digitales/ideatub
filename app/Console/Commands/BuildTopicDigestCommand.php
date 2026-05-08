<?php

namespace App\Console\Commands;

use App\Jobs\BuildTopicDigestJob;
use App\Models\User;
use Illuminate\Console\Command;

class BuildTopicDigestCommand extends Command
{
    protected $signature = 'compactions:topic-digest {scope_type} {scope_key} {topic} {--user=}';

    protected $description = 'Build an on-demand compaction:topic-digest for a scope+topic.';

    private const SCOPE_TYPES = ['project', 'tag', 'global', 'insights'];

    public function handle(): int
    {
        $scopeType = (string) $this->argument('scope_type');
        $scopeKey = trim((string) $this->argument('scope_key'));
        $topic = trim((string) $this->argument('topic'));

        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            $this->error("Unknown scope_type: {$scopeType}.");

            return self::FAILURE;
        }

        if ($scopeKey === '') {
            $this->error('scope_key must not be empty.');

            return self::FAILURE;
        }

        if (in_array($scopeType, ['global', 'insights'], true) && $scopeKey !== 'global') {
            $this->error("scope_key must be 'global' for {$scopeType} scope.");

            return self::FAILURE;
        }

        if ($topic === '') {
            $this->error('topic must not be empty.');

            return self::FAILURE;
        }

        $userIdOption = $this->option('user');
        if ($userIdOption !== null) {
            if (! is_string($userIdOption) || trim($userIdOption) === '' || ! ctype_digit(trim($userIdOption))) {
                $this->error('--user must be a numeric user id.');

                return self::FAILURE;
            }

            $userId = (int) trim($userIdOption);

            if (! User::query()->whereKey($userId)->exists()) {
                $this->error("User #{$userId} does not exist.");

                return self::FAILURE;
            }
        } else {
            $userId = (int) (User::query()->orderBy('id')->value('id') ?? 0);

            if ($userId <= 0) {
                $this->error('No users found. Pass --user=<id> or seed a user.');

                return self::FAILURE;
            }
        }

        BuildTopicDigestJob::dispatch($userId, $scopeType, $scopeKey, $topic);

        $this->info("Dispatched compactions:topic-digest for user {$userId}, scope {$scopeType}/{$scopeKey}, topic '{$topic}'.");

        return self::SUCCESS;
    }
}

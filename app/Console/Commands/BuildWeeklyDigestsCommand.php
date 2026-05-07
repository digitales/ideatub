<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Models\Thought;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildWeeklyDigestsCommand extends Command
{
    protected $signature = 'compactions:digest {--user=}';

    protected $description = 'Enqueue weekly digest compaction jobs for active scopes.';

    public function __construct(
        private readonly WorkingMemoryScopeResolver $scopeResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $windowDays = (int) config('working_memory.digest_window_days', 7);
        $cutoff = Carbon::now()->subDays($windowDays);

        $userIdOption = $this->option('user');
        $query = Thought::query()->where('created_at', '>=', $cutoff);
        if ($userIdOption !== null && $userIdOption !== '') {
            if (! is_string($userIdOption) || ! ctype_digit($userIdOption)) {
                $this->error('--user must be a numeric user id.');

                return self::FAILURE;
            }
            $query->where('user_id', (int) $userIdOption);
        }

        $thoughts = $query->orderByDesc('created_at')->limit(2000)->get();

        $seen = [];
        foreach ($thoughts as $thought) {
            $userId = (int) $thought->user_id;
            if ($userId <= 0) {
                continue;
            }
            foreach ($this->scopeResolver->forThought($thought) as $scope) {
                $key = $userId.'|'.$scope['scope_type'].'|'.$scope['scope_key'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                BuildScopeDigestJob::dispatch($userId, $scope['scope_type'], $scope['scope_key']);
            }
        }

        $this->info(sprintf('Queued %d digest job(s).', count($seen)));

        return self::SUCCESS;
    }
}

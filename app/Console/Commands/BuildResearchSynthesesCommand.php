<?php

namespace App\Console\Commands;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Services\WorkingMemory\MemoryInsightsService;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Console\Command;

class BuildResearchSynthesesCommand extends Command
{
    protected $signature = 'compactions:research {--user=}';

    protected $description = 'Enqueue research synthesis compaction jobs for scopes at or above threshold.';

    public function __construct(
        private readonly WorkingMemoryScopeResolver $scopeResolver,
        private readonly MemoryInsightsService $insights,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minThoughts = (int) config('working_memory.research_synth_min_thoughts', 8);

        $userIdOption = $this->option('user');
        $query = Thought::query();
        if ($userIdOption !== null && $userIdOption !== '') {
            if (! is_string($userIdOption) || ! ctype_digit($userIdOption)) {
                $this->error('--user must be a numeric user id.');

                return self::FAILURE;
            }
            $query->where('user_id', (int) $userIdOption);
        }

        $thoughts = $query->orderByDesc('created_at')->limit(5000)->get()
            ->filter(fn (Thought $t): bool => $this->insights->isResearchThought($t))
            ->values();

        $countsByScope = [];
        foreach ($thoughts as $thought) {
            $userId = (int) $thought->user_id;
            if ($userId <= 0) {
                continue;
            }
            foreach ($this->scopeResolver->forThought($thought) as $scope) {
                $key = $userId.'|'.$scope['scope_type'].'|'.$scope['scope_key'];
                $countsByScope[$key] = ($countsByScope[$key] ?? 0) + 1;
            }
        }

        $dispatched = 0;
        foreach ($countsByScope as $key => $count) {
            if ($count < $minThoughts) {
                continue;
            }
            [$userId, $scopeType, $scopeKey] = explode('|', $key, 3);
            SynthesizeResearchCompactionJob::dispatch((int) $userId, $scopeType, $scopeKey);
            $dispatched++;
        }

        $this->info(sprintf('Queued %d research synthesis job(s).', $dispatched));

        return self::SUCCESS;
    }
}

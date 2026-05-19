<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemorySnapshotSuperseder;
use App\Services\WorkingMemory\WorkingMemoryVersionSuperseder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RetryWorkingMemorySupersedeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public string $dedupeFamily,
        public ?string $winnerThoughtId = null,
        public ?string $winnerVersionId = null,
    ) {}

    public function handle(
        WorkingMemorySnapshotSuperseder $snapshotSuperseder,
        WorkingMemoryVersionSuperseder $versionSuperseder,
    ): void {
        DB::transaction(function () use ($snapshotSuperseder, $versionSuperseder): void {
            if ($this->winnerThoughtId !== null) {
                $winner = Thought::query()
                    ->where('user_id', $this->userId)
                    ->find($this->winnerThoughtId);

                if ($winner instanceof Thought) {
                    $others = Thought::query()
                        ->where('user_id', $this->userId)
                        ->where('source_metadata->working_memory->dedupe_family', $this->dedupeFamily)
                        ->whereKeyNot($winner->id)
                        ->where(function ($q): void {
                            $q->where('source_metadata->working_memory->is_current', true)
                                ->orWhere('is_visible_in_stream', true);
                        })
                        ->get();

                    foreach ($others as $prior) {
                        $snapshotSuperseder->supersede($prior, $winner);
                    }
                }
            }

            if ($this->winnerVersionId !== null) {
                $winnerVersion = WorkingMemoryVersion::query()->find($this->winnerVersionId);
                if ($winnerVersion instanceof WorkingMemoryVersion) {
                    $versionSuperseder->supersedeAllExcept($winnerVersion->workingMemory, $winnerVersion);
                }
            }
        });
    }
}

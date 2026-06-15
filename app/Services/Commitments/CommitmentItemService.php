<?php

namespace App\Services\Commitments;

use App\Models\CommitmentItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CommitmentItemService
{
    public function markDone(CommitmentItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $item->update([
                'status' => 'done',
                'snoozed_until' => null,
                'closed_at' => now(),
            ]);
        });
    }

    public function snooze(CommitmentItem $item, string $preset): void
    {
        $until = match ($preset) {
            'tomorrow' => now('UTC')->addDay()->startOfDay(),
            'next_week' => now('UTC')->addWeek()->startOfDay(),
            default => throw new InvalidArgumentException('Invalid snooze preset.'),
        };

        $item->update([
            'snoozed_until' => $until,
        ]);
    }
}

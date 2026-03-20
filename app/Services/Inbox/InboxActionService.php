<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\InboxItemAction;
use App\Models\Thought;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InboxActionService
{
    public function __construct(
        private ThoughtCaptureService $thoughtCaptureService
    ) {}

    public function markDone(InboxItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $actedAt = now('UTC');

            $item->update([
                'status' => 'done',
                'snoozed_until' => null,
                'actioned_at' => $actedAt,
            ]);

            InboxItemAction::query()->create([
                'inbox_item_id' => $item->id,
                'action_type' => 'done',
                'metadata' => null,
                'created_at' => $actedAt,
            ]);
        });
    }

    public function snooze(InboxItem $item, string $preset): void
    {
        $until = match ($preset) {
            'tomorrow' => now('UTC')->addDay()->startOfDay(),
            'next_week' => now('UTC')->addWeek()->startOfDay(),
            default => throw new InvalidArgumentException('Invalid snooze preset.'),
        };

        DB::transaction(function () use ($item, $preset, $until): void {
            $actedAt = now('UTC');

            $item->update([
                'status' => 'pending',
                'actioned_at' => null,
                'snoozed_until' => $until,
            ]);

            InboxItemAction::query()->create([
                'inbox_item_id' => $item->id,
                'action_type' => 'snooze',
                'metadata' => ['snoozed_until' => $until->toIso8601String(), 'preset' => $preset],
                'created_at' => $actedAt,
            ]);
        });
    }

    public function saveAsThought(InboxItem $item): string
    {
        $reservation = DB::transaction(function () use ($item): array {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $existing = $locked->actions()->where('action_type', 'save_as_thought')->latest('id')->first();

            if ($existing !== null) {
                $existingThoughtId = $this->extractThoughtId($existing);
                if ($existingThoughtId !== null) {
                    return ['thought_id' => $existingThoughtId];
                }

                $recoveredThoughtId = $this->recoverThoughtIdForPendingSave($locked, $existing);
                if ($recoveredThoughtId !== null) {
                    return ['thought_id' => $recoveredThoughtId];
                }

                throw new \RuntimeException('Inbox item already has an incomplete save-as-thought action.');
            }

            $pendingAction = InboxItemAction::query()->create([
                'inbox_item_id' => $locked->id,
                'action_type' => 'save_as_thought',
                'metadata' => ['status' => 'pending'],
                'created_at' => now('UTC'),
            ]);

            return [
                'reservation_id' => $pendingAction->id,
                'content' => $this->buildThoughtContent($locked),
                'user_id' => (int) $locked->user_id,
                'source_metadata' => [
                    'inbox_item_id' => $locked->id,
                    'generator_type' => $locked->generator_type,
                ],
            ];
        });

        if (isset($reservation['thought_id'])) {
            return $reservation['thought_id'];
        }

        try {
            $result = $this->thoughtCaptureService->create([
                'content' => $reservation['content'],
                'user_id' => $reservation['user_id'],
                'source' => 'inbox',
                'source_metadata' => $reservation['source_metadata'],
                'no_chunking' => true,
            ]);
        } catch (\Throwable $e) {
            $this->cleanupPendingSaveAsThoughtAction($item, $reservation['reservation_id']);

            throw $e;
        }

        $thought = $result['thought'] ?? $result['root'] ?? null;
        if ($thought === null) {
            $this->cleanupPendingSaveAsThoughtAction($item, $reservation['reservation_id']);

            throw new \RuntimeException('Thought capture did not return a thought.');
        }

        return DB::transaction(function () use ($item, $reservation, $thought): string {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $action = $locked->actions()
                ->whereKey($reservation['reservation_id'])
                ->where('action_type', 'save_as_thought')
                ->firstOrFail();

            $existingThoughtId = $this->extractThoughtId($action);
            if ($existingThoughtId !== null) {
                return $existingThoughtId;
            }

            $actedAt = now('UTC');

            $action->update([
                'metadata' => ['thought_id' => $thought->id],
            ]);

            $locked->update([
                'status' => 'done',
                'snoozed_until' => null,
                'actioned_at' => $actedAt,
            ]);

            return $thought->id;
        });
    }

    private function buildThoughtContent(InboxItem $item): string
    {
        $content = $item->title."\n\n".$item->body;

        if (! empty($item->source_data)) {
            $content .= "\n\nSource data:\n".json_encode($item->source_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $content;
    }

    private function cleanupPendingSaveAsThoughtAction(InboxItem $item, int $reservationId): void
    {
        DB::transaction(function () use ($item, $reservationId): void {
            $locked = InboxItem::query()->whereKey($item->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $action = $locked->actions()
                ->whereKey($reservationId)
                ->where('action_type', 'save_as_thought')
                ->first();

            if ($action !== null && $this->extractThoughtId($action) === null) {
                $action->delete();
            }
        });
    }

    private function extractThoughtId(InboxItemAction $action): ?string
    {
        $thoughtId = $action->metadata['thought_id'] ?? null;

        return is_string($thoughtId) && $thoughtId !== '' ? $thoughtId : null;
    }

    private function recoverThoughtIdForPendingSave(InboxItem $item, InboxItemAction $action): ?string
    {
        $thought = Thought::query()
            ->where('user_id', $item->user_id)
            ->where('source', 'inbox')
            ->where('source_metadata->inbox_item_id', $item->id)
            ->latest('created_at')
            ->first();

        if ($thought === null) {
            return null;
        }

        $actedAt = now('UTC');

        $action->update([
            'metadata' => ['thought_id' => $thought->id],
        ]);

        $item->update([
            'status' => 'done',
            'snoozed_until' => null,
            'actioned_at' => $actedAt,
        ]);

        return $thought->id;
    }
}

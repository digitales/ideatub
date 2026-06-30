<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\Email\EmailReviewActionService;
use App\Support\Inbox\InboxGroupDescriptor;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class InboxBulkActionService
{
    public function __construct(
        private readonly InboxActionService $inboxActionService,
        private readonly EmailReviewActionService $emailReviewActionService,
    ) {}

    public function apply(User $user, string $generatorType, string $action): int
    {
        $allowed = InboxGroupDescriptor::bulkActionsFor($generatorType);
        if (! in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Bulk action is not allowed for this inbox group.');
        }

        $items = InboxItem::query()
            ->forUser($user)
            ->actionable()
            ->where('generator_type', $generatorType)
            ->orderByDesc('generated_at')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        return match ($action) {
            'done_all', 'ok_all' => $this->markAllDone($items),
            'allow_all' => $this->bulkEmailReview($user, $items, 'allow'),
            'ignore_all' => $this->bulkEmailReview($user, $items, 'ignore'),
            default => throw new InvalidArgumentException('Unsupported bulk action.'),
        };
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     */
    private function markAllDone(Collection $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            $this->inboxActionService->markDone($item);
            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     */
    private function bulkEmailReview(User $user, Collection $items, string $action): int
    {
        $count = 0;

        foreach ($items as $item) {
            if ($item->generator_type !== 'email_sender_review') {
                continue;
            }

            try {
                $applied = $this->emailReviewActionService->applySenderClassification($item, $user, $action);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            if (! $applied) {
                continue;
            }

            if ($action === 'allow') {
                try {
                    $this->emailReviewActionService->saveReviewedEmailAsThought($item, $user);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $count++;
        }

        return $count;
    }
}

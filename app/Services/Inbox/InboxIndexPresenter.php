<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\User;
use App\Support\Inbox\InboxGroupDescriptor;
use App\Support\Inbox\InboxGroupViewModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class InboxIndexPresenter
{
    /**
     * @return array{
     *     groups: Collection<int, InboxGroupViewModel>,
     *     singles: LengthAwarePaginator,
     *     inboxInitialCount: int
     * }
     */
    public function present(User $user, int $singlesPerPage = 20): array
    {
        $minimumGroupCount = max(2, (int) config('inbox.group_minimum_count', 2));

        $all = InboxItem::query()
            ->forUser($user)
            ->actionable()
            ->orderByDesc('generated_at')
            ->get();

        $countsByType = $all->groupBy('generator_type')->map->count();
        $groupedTypes = $countsByType
            ->filter(fn (int $count): bool => $count >= $minimumGroupCount)
            ->keys();

        $groups = $groupedTypes
            ->map(function (string $generatorType) use ($all): InboxGroupViewModel {
                $items = $all->where('generator_type', $generatorType)->values();
                $summary = InboxGroupDescriptor::summary($generatorType, $items);

                return new InboxGroupViewModel(
                    generatorType: $generatorType,
                    title: $summary['title'],
                    subtitle: $summary['subtitle'],
                    items: $items,
                    bulkActions: InboxGroupDescriptor::bulkActionsFor($generatorType),
                );
            })
            ->sortByDesc(fn (InboxGroupViewModel $group): mixed => $group->items->max('generated_at'))
            ->values();

        $singles = $all
            ->reject(fn (InboxItem $item): bool => $groupedTypes->contains($item->generator_type))
            ->values();

        $page = max(1, (int) request()->query('page', 1));
        $paginatedSingles = new Paginator(
            $singles->forPage($page, $singlesPerPage)->values(),
            $singles->count(),
            $singlesPerPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );

        return [
            'groups' => $groups,
            'singles' => $paginatedSingles,
            'inboxInitialCount' => $all->count(),
        ];
    }
}

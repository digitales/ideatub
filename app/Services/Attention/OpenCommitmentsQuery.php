<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\CommitmentItem;
use Illuminate\Support\Str;

final class OpenCommitmentsQuery
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    /**
     * @return list<AttentionItemData>
     */
    public function forUser(int $userId): array
    {
        $limit = max(1, (int) config('pulse.max_commitments', 15));

        $items = CommitmentItem::query()
            ->forUser($userId)
            ->open()
            ->with('project')
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $items->map(function (CommitmentItem $item): AttentionItemData {
            $href = $item->external_url
                ?? ($item->source_thought_id !== null
                    ? route('thoughts.show', ['thought' => $item->source_thought_id])
                    : route('pulse.show'));

            if ($item->scope_type === 'project' && $item->scope_key !== null && $item->project !== null) {
                $href = route('projects.memory.show', $item->project);
            }

            return new AttentionItemData(
                kind: $item->type,
                severity: null,
                title: Str::limit($item->title, 120),
                subtitle: $item->project?->title ?? $item->owner_label,
                href: $href,
                meta: [
                    'scope_type' => $item->scope_type,
                    'scope_key' => $item->scope_key,
                    'external_key' => $item->external_key,
                ],
                sourceRef: $item->source_version_id !== null
                    ? ['type' => 'working_memory_version', 'id' => (string) $item->source_version_id]
                    : ($item->source_thought_id !== null
                        ? ['type' => 'thought', 'id' => (string) $item->source_thought_id]
                        : null),
                commitmentId: (string) $item->id,
            );
        })->all();
    }
}

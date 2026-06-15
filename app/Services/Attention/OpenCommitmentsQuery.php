<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\CommitmentItem;
use App\Services\WorkingMemory\WorkingMemoryLegacyRowCitationResolver;
use Illuminate\Support\Str;

final class OpenCommitmentsQuery
{
    public function __construct(
        private readonly WorkingMemoryLegacyRowCitationResolver $citationResolver,
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
            ->with(['project', 'sourceVersion'])
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $items->map(function (CommitmentItem $item): AttentionItemData {
            $sourceThoughtId = $this->resolveSourceThoughtId($item);

            $href = $item->external_url;
            if ($href === null && $sourceThoughtId !== null) {
                $href = route('thoughts.show', ['thought' => $sourceThoughtId]);
            } elseif ($href === null && $item->scope_type === 'project' && $item->project !== null) {
                $href = route('projects.memory.show', $item->project);
            } elseif ($href === null) {
                $href = route('pulse.show');
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
                sourceRef: $sourceThoughtId !== null
                    ? ['type' => 'thought', 'id' => $sourceThoughtId]
                    : ($item->source_version_id !== null
                        ? ['type' => 'working_memory_version', 'id' => (string) $item->source_version_id]
                        : null),
                commitmentId: (string) $item->id,
            );
        })->all();
    }

    private function resolveSourceThoughtId(CommitmentItem $item): ?string
    {
        if ($item->source_thought_id !== null) {
            return (string) $item->source_thought_id;
        }

        if (! in_array($item->type, ['wm_open_question', 'wm_next_action'], true)) {
            return null;
        }

        $version = $item->sourceVersion;
        if ($version === null) {
            return null;
        }

        $sections = $version->structured_sections_json ?? [];
        if (! is_array($sections)) {
            return null;
        }

        $section = $item->type === 'wm_open_question' ? 'Open Questions' : 'Next Actions';
        $entries = $sections[$section] ?? [];
        if (! is_array($entries)) {
            return null;
        }

        $title = trim($item->title);
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '' || ($text !== $title && ! str_starts_with($text, $title))) {
                continue;
            }

            $citations = $entry['citations'] ?? [];
            if (! is_array($citations)) {
                continue;
            }

            $link = $this->citationResolver->resolvePrimaryThought($citations);

            return $link['thought_id'] ?? null;
        }

        return null;
    }
}

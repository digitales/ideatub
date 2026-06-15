<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Str;

final class MeetingActionItemsQuery
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    /**
     * @return list<AttentionItemData>
     */
    public function forUser(int $userId): array
    {
        $days = max(1, (int) config('pulse.meeting_action_days', 30));
        $cutoff = now()->subDays($days);
        $limit = max(1, (int) config('pulse.max_commitments', 15));

        $versions = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:meeting')
            ->where('created_at', '>=', $cutoff)
            ->whereHas('workingMemory', fn ($query) => $query->where('user_id', $userId))
            ->with('workingMemory')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        if ($versions->isEmpty()) {
            return [];
        }

        $projectMemories = $versions
            ->map(fn (WorkingMemoryVersion $version) => $version->workingMemory)
            ->filter()
            ->unique(fn ($memory) => (string) $memory->id);
        $projects = $this->scopeResolver->projectsFor($userId, $projectMemories);

        $items = [];
        $seenActionTexts = [];

        foreach ($versions as $version) {
            $memory = $version->workingMemory;
            if ($memory === null) {
                continue;
            }

            $scope = $this->scopeResolver->resolve($memory, $projects);
            $sections = $version->structured_sections_json ?? [];
            $actionItems = is_array($sections) ? ($sections['Action Items'] ?? []) : [];

            foreach ($this->sectionTexts($actionItems) as $text) {
                $hash = substr(hash('sha256', $text), 0, 16);
                if (isset($seenActionTexts[$hash])) {
                    continue;
                }
                $seenActionTexts[$hash] = true;

                $items[] = new AttentionItemData(
                    kind: 'meeting_action',
                    severity: null,
                    title: Str::limit($text, 120),
                    subtitle: $scope['project_title'] ?? $scope['title'],
                    href: route('memory.compactions.show', [
                        'scopeType' => $memory->scope_type,
                        'scopeKey' => $memory->scope_key,
                        'versionId' => $version->id,
                    ]),
                    meta: [
                        'scope_type' => $memory->scope_type,
                        'scope_key' => $memory->scope_key,
                        'compaction_created_at' => $version->created_at?->toIso8601String(),
                    ],
                    sourceRef: [
                        'type' => 'working_memory_version',
                        'id' => (string) $version->id,
                    ],
                );
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function sectionTexts(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $texts = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $text = trim($entry);
            } elseif (is_array($entry)) {
                $text = trim((string) ($entry['text'] ?? ''));
            } else {
                continue;
            }

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}

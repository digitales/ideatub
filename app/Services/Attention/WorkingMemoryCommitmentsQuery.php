<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Str;

final class WorkingMemoryCommitmentsQuery
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    /**
     * @return list<AttentionItemData>
     */
    public function forUser(int $userId): array
    {
        $memories = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', 'project')
            ->with('latestVersion')
            ->get();

        if ($memories->isEmpty()) {
            return [];
        }

        $projects = $this->scopeResolver->projectsFor($userId, $memories);
        $perProjectLimit = max(1, (int) config('pulse.max_commitments_per_project', 5));
        $totalLimit = max(1, (int) config('pulse.max_commitments', 15));
        $items = [];

        foreach ($memories as $memory) {
            $canonical = $this->canonicalVersion($memory);
            if ($canonical === null) {
                continue;
            }

            if (! in_array($canonical->authoring_status, ['validated', 'external'], true)) {
                continue;
            }

            $sections = $canonical->structured_sections_json ?? [];
            if (! is_array($sections) || $sections === []) {
                continue;
            }

            $scope = $this->scopeResolver->resolve($memory, $projects);
            $projectItems = [];

            foreach (['Next Actions' => 'wm_next_action', 'Open Questions' => 'wm_open_question'] as $section => $kind) {
                foreach ($this->sectionTexts($sections[$section] ?? []) as $text) {
                    $projectItems[] = new AttentionItemData(
                        kind: $kind,
                        severity: null,
                        title: Str::limit($text, 120),
                        subtitle: $scope['project_title'] ?? $scope['title'],
                        href: $scope['href'],
                        meta: [
                            'scope_type' => $memory->scope_type,
                            'scope_key' => $memory->scope_key,
                            'section' => $section,
                        ],
                        sourceRef: [
                            'type' => 'working_memory_version',
                            'id' => (string) $canonical->id,
                        ],
                    );
                }
            }

            $items = array_merge($items, array_slice($projectItems, 0, $perProjectLimit));
        }

        return array_slice($items, 0, $totalLimit);
    }

    private function canonicalVersion(WorkingMemory $memory): ?WorkingMemoryVersion
    {
        $authoritative = $memory->versions()
            ->whereIn('build_type', ['consolidated', 'external'])
            ->whereNull('superseded_at')
            ->orderByDesc('created_at')
            ->first();

        if ($authoritative instanceof WorkingMemoryVersion) {
            return $authoritative;
        }

        return $memory->latestVersion;
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

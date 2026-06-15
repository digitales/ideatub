<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\AttentionScopeResolver;
use App\Services\Inbox\Contracts\InboxGenerator;

class MeetingActionInboxGenerator implements InboxGenerator
{
    public function __construct(
        private readonly AttentionScopeResolver $scopeResolver,
    ) {}

    public function generate(User $user): array
    {
        if (! config('features.attention_pulse')) {
            return [];
        }

        $days = max(1, (int) config('pulse.meeting_action_days', 30));
        $cutoff = now()->subDays($days);
        $limit = max(1, (int) config('pulse.max_commitments', 15));

        $versions = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:meeting')
            ->where('created_at', '>=', $cutoff)
            ->whereHas('workingMemory', fn ($query) => $query->where('user_id', $user->id))
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
        $projects = $this->scopeResolver->projectsFor($user->id, $projectMemories);

        $payloads = [];

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

                $payloads[] = [
                    'generator_type' => 'meeting_action',
                    'title' => 'Meeting action item',
                    'body' => "{$scope['title']}: {$text}",
                    'dedupe_key' => 'meeting_action:'.$version->id.':'.$hash,
                    'generated_at' => now(),
                    'source_data' => [
                        'version_id' => $version->id,
                        'scope_type' => $memory->scope_type,
                        'scope_key' => $memory->scope_key,
                        'href' => route('memory.compactions.show', [
                            'scopeType' => $memory->scope_type,
                            'scopeKey' => $memory->scope_key,
                            'versionId' => $version->id,
                        ]),
                    ],
                ];

                if (count($payloads) >= $limit) {
                    break 2;
                }
            }
        }

        return $payloads;
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

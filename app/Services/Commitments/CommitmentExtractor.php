<?php

namespace App\Services\Commitments;

use App\Models\CommitmentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryLegacyRowCitationResolver;
use Illuminate\Support\Str;

final class CommitmentExtractor
{
    public function __construct(
        private readonly WorkingMemoryLegacyRowCitationResolver $citationResolver,
    ) {}

    public function fromMeetingCompaction(WorkingMemoryVersion $version): int
    {
        if ($version->build_type !== 'compaction:meeting') {
            return 0;
        }

        $memory = $version->workingMemory;
        if ($memory === null) {
            return 0;
        }

        $sections = $version->structured_sections_json ?? [];
        $items = is_array($sections) ? ($sections['Action Items'] ?? []) : [];

        return $this->upsertSectionItems(
            userId: (int) $memory->user_id,
            type: 'meeting_action',
            sectionEntries: $items,
            memory: $memory,
            version: $version,
            dedupePrefix: 'meeting_action:'.$version->id,
        );
    }

    public function fromWorkingMemoryVersion(WorkingMemoryVersion $version): int
    {
        if (! in_array($version->authoring_status, ['validated', 'external'], true)) {
            return 0;
        }

        if (! in_array($version->build_type, ['consolidated', 'external', 'incremental'], true)) {
            return 0;
        }

        $memory = $version->workingMemory;
        if ($memory === null) {
            return 0;
        }

        $sections = $version->structured_sections_json ?? [];
        if (! is_array($sections) || $sections === []) {
            return 0;
        }

        $created = 0;
        $created += $this->upsertSectionItems(
            userId: (int) $memory->user_id,
            type: 'wm_next_action',
            sectionEntries: $sections['Next Actions'] ?? [],
            memory: $memory,
            version: $version,
            dedupePrefix: 'wm_next_action:'.$version->id,
        );
        $created += $this->upsertSectionItems(
            userId: (int) $memory->user_id,
            type: 'wm_open_question',
            sectionEntries: $sections['Open Questions'] ?? [],
            memory: $memory,
            version: $version,
            dedupePrefix: 'wm_open_question:'.$version->id,
        );

        return $created;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function fromJiraEvent(User $user, array $event): int
    {
        $issueKey = (string) data_get($event, 'source_metadata.jira_issue_key', '');
        if ($issueKey === '') {
            return 0;
        }

        $eventType = (string) data_get($event, 'source_metadata.jira_event_type', '');
        if (! in_array($eventType, ['updated', 'comment'], true)) {
            return 0;
        }

        $title = (string) ($event['content'] ?? $issueKey);
        $url = (string) data_get($event, 'source_metadata.jira_url', '');
        $updatedAt = (string) data_get($event, 'source_metadata.jira_updated_at', '');
        $dedupeKey = 'jira_follow_up:'.$issueKey.':'.($updatedAt !== '' ? $updatedAt : $eventType);

        return $this->upsertOpenItem(
            userId: (int) $user->id,
            type: 'jira_follow_up',
            title: Str::limit($title, 500),
            body: null,
            dedupeKey: $dedupeKey,
            memory: null,
            version: null,
            sourceThoughtId: null,
            externalKey: $issueKey,
            externalUrl: $url !== '' ? $url : null,
            sourceData: [
                'jira_event_type' => $eventType,
                'jira_updated_at' => $updatedAt,
            ],
        ) ? 1 : 0;
    }

    private function upsertSectionItems(
        int $userId,
        string $type,
        mixed $sectionEntries,
        WorkingMemory $memory,
        WorkingMemoryVersion $version,
        string $dedupePrefix,
    ): int {
        if (! is_array($sectionEntries)) {
            return 0;
        }

        $created = 0;
        foreach ($sectionEntries as $index => $entry) {
            $text = $this->entryText($entry);
            if ($text === '') {
                continue;
            }

            $hash = substr(hash('sha256', $text), 0, 16);
            $dedupeKey = $dedupePrefix.':'.$hash;
            $sourceThoughtId = $this->sourceThoughtIdFromEntry($entry);

            if ($this->upsertOpenItem(
                userId: $userId,
                type: $type,
                title: Str::limit($text, 500),
                body: $text,
                dedupeKey: $dedupeKey,
                memory: $memory,
                version: $version,
                sourceThoughtId: $sourceThoughtId,
                externalKey: null,
                externalUrl: null,
                sourceData: ['section_index' => $index],
            )) {
                $created++;
            }
        }

        return $created;
    }

    private function upsertOpenItem(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        string $dedupeKey,
        ?WorkingMemory $memory,
        ?WorkingMemoryVersion $version,
        ?string $sourceThoughtId,
        ?string $externalKey,
        ?string $externalUrl,
        ?array $sourceData,
    ): bool {
        $existing = CommitmentItem::query()
            ->forUser($userId)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', 'open')
            ->first();

        if ($existing !== null) {
            return false;
        }

        $projectId = null;
        if ($memory !== null && $memory->scope_type === 'project') {
            $scopeKey = (string) $memory->scope_key;
            $project = null;
            if (Str::isUuid($scopeKey)) {
                $project = Project::query()
                    ->where('user_id', $userId)
                    ->find($scopeKey);
            } else {
                $project = Project::query()
                    ->where('user_id', $userId)
                    ->get()
                    ->first(fn (Project $candidate): bool => Str::lower(Str::slug($candidate->title)) === Str::lower($scopeKey));
            }

            $projectId = $project?->id;
        }

        CommitmentItem::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'status' => 'open',
            'title' => $title,
            'body' => $body,
            'project_id' => $projectId,
            'scope_type' => $memory?->scope_type,
            'scope_key' => $memory?->scope_key,
            'source_thought_id' => $sourceThoughtId,
            'source_version_id' => $version?->id,
            'external_key' => $externalKey,
            'external_url' => $externalUrl,
            'dedupe_key' => $dedupeKey,
            'source_data' => $sourceData,
            'opened_at' => now(),
        ]);

        return true;
    }

    private function entryText(mixed $entry): string
    {
        if (is_string($entry)) {
            return trim($entry);
        }

        if (is_array($entry)) {
            return trim((string) ($entry['text'] ?? ''));
        }

        return '';
    }

    private function sourceThoughtIdFromEntry(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return null;
        }

        $citations = $entry['citations'] ?? [];
        if (! is_array($citations)) {
            return null;
        }

        $link = $this->citationResolver->resolvePrimaryThought($citations);

        return $link['thought_id'] ?? null;
    }
}

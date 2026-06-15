<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\DataTransferObjects\AttentionOverviewData;
use App\DataTransferObjects\AttentionSectionData;
use App\Models\CommitmentItem;

final class AttentionOverviewBuilder
{
    public function __construct(
        private readonly MemoryHealthAttentionQuery $memoryHealth,
        private readonly OpenCommitmentsQuery $openCommitments,
        private readonly WorkingMemoryCommitmentsQuery $workingMemoryCommitments,
        private readonly MeetingActionItemsQuery $meetingActions,
        private readonly JiraActivityQuery $jiraActivity,
    ) {}

    public function build(int $userId): AttentionOverviewData
    {
        $sections = [];

        $memoryHealth = $this->memoryHealth->groupedForUser($userId);

        if ($memoryHealth['operational'] !== []) {
            $sections[] = new AttentionSectionData(
                key: 'memory_health',
                title: 'Memory health',
                description: 'Global, project, and insights scopes that may need a refresh, agent sync, or consolidate.',
                items: $memoryHealth['operational'],
            );
        }

        if ($memoryHealth['tag'] !== []) {
            $sections[] = new AttentionSectionData(
                key: 'tag_memory_health',
                title: 'Tag memory',
                description: 'Tag-scoped working memory from captures and Stream filters. Forced tags show individually; other tag fallbacks are grouped.',
                items: $memoryHealth['tag'],
            );
        }

        $commitmentItems = $this->resolveCommitmentItems($userId);
        if ($commitmentItems !== []) {
            $sections[] = new AttentionSectionData(
                key: 'open_commitments',
                title: 'Open commitments',
                description: 'Next actions, open questions, and meeting follow-ups across projects.',
                items: $commitmentItems,
            );
        }

        $jiraItems = $this->jiraActivity->forUser($userId);
        if ($jiraItems !== []) {
            $sections[] = new AttentionSectionData(
                key: 'recent_jira',
                title: 'Recent Jira',
                description: 'Issues you created, updated, or commented on recently.',
                items: $jiraItems,
            );
        }

        return new AttentionOverviewData(sections: $sections);
    }

    /**
     * @return list<AttentionItemData>
     */
    private function resolveCommitmentItems(int $userId): array
    {
        $fromStore = $this->openCommitments->forUser($userId);
        if ($fromStore !== []) {
            return $fromStore;
        }

        return array_merge(
            $this->workingMemoryCommitments->forUser($userId),
            $this->meetingActions->forUser($userId),
        );
    }
}

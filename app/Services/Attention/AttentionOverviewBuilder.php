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

        $memoryItems = $this->memoryHealth->forUser($userId);
        if ($memoryItems !== []) {
            $sections[] = new AttentionSectionData(
                key: 'memory_health',
                title: 'Memory health',
                description: 'Working memory scopes that may need a refresh, agent sync, or consolidate.',
                items: $memoryItems,
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

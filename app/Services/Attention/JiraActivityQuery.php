<?php

namespace App\Services\Attention;

use App\DataTransferObjects\AttentionItemData;
use App\Models\Thought;
use Illuminate\Support\Str;

final class JiraActivityQuery
{
    /**
     * @return list<AttentionItemData>
     */
    public function forUser(int $userId, ?int $daysOverride = null): array
    {
        $days = max(1, $daysOverride ?? (int) config('pulse.jira_days', 14));
        $cutoff = now()->subDays($days);
        $limit = max(1, (int) config('pulse.max_jira', 15));

        $thoughts = Thought::query()
            ->where('user_id', $userId)
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalSourceType('jira')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $byIssue = [];

        foreach ($thoughts as $thought) {
            $issueKey = (string) data_get($thought->source_metadata, 'jira_issue_key', '');
            if ($issueKey === '') {
                continue;
            }

            $updatedAt = data_get($thought->source_metadata, 'jira_updated_at');
            $parsed = is_string($updatedAt) ? strtotime($updatedAt) : false;
            if ($parsed !== false && $parsed < $cutoff->getTimestamp()) {
                continue;
            }

            if (! isset($byIssue[$issueKey])) {
                $byIssue[$issueKey] = $thought;
            }
        }

        $items = [];
        foreach (array_slice($byIssue, 0, $limit, true) as $issueKey => $thought) {
            $summary = (string) data_get($thought->source_metadata, 'jira_summary', $issueKey);
            $url = (string) data_get($thought->source_metadata, 'jira_url', '');
            $eventType = (string) data_get($thought->source_metadata, 'jira_event_type', 'activity');
            $updatedLabel = is_string($updatedAt = data_get($thought->source_metadata, 'jira_updated_at'))
                ? $updatedAt
                : $thought->created_at?->toIso8601String();

            $items[] = new AttentionItemData(
                kind: 'jira_issue',
                severity: null,
                title: $issueKey.': '.Str::limit($summary, 80),
                subtitle: ucfirst($eventType).' · '.$updatedLabel,
                href: $url !== '' ? $url : route('idea.stream.jira'),
                meta: [
                    'issue_key' => $issueKey,
                    'project_key' => data_get($thought->source_metadata, 'jira_project_key'),
                ],
                sourceRef: [
                    'type' => 'thought',
                    'id' => (string) $thought->id,
                ],
            );
        }

        return $items;
    }
}

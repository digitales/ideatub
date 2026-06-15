<?php

namespace App\Services\Inbox\Generators;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;

class JiraFollowUpInboxGenerator implements InboxGenerator
{
    private const FOLLOW_UP_DAYS = 3;

    public function generate(User $user): array
    {
        if (! config('features.attention_pulse')) {
            return [];
        }

        $thoughts = Thought::query()
            ->where('user_id', $user->id)
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalSourceType('jira')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $cutoff = now()->subDays(self::FOLLOW_UP_DAYS);
        $payloads = [];

        foreach ($thoughts as $thought) {
            $eventType = (string) data_get($thought->source_metadata, 'jira_event_type', '');
            if (! in_array($eventType, ['updated', 'comment'], true)) {
                continue;
            }

            $issueKey = (string) data_get($thought->source_metadata, 'jira_issue_key', '');
            if ($issueKey === '') {
                continue;
            }

            $updatedAt = data_get($thought->source_metadata, 'jira_updated_at');
            $parsed = is_string($updatedAt) ? strtotime($updatedAt) : false;
            if ($parsed !== false && $parsed < $cutoff->getTimestamp()) {
                continue;
            }

            $summary = (string) data_get($thought->source_metadata, 'jira_summary', $issueKey);
            $url = (string) data_get($thought->source_metadata, 'jira_url', '');
            $updatedLabel = is_string($updatedAt) ? $updatedAt : ($thought->created_at?->toIso8601String() ?? '');

            $payloads[] = [
                'generator_type' => 'jira_follow_up',
                'title' => 'Jira follow-up: '.$issueKey,
                'body' => "{$summary}\n".($url !== '' ? $url : route('idea.stream.jira')),
                'dedupe_key' => 'jira_follow_up:'.$issueKey.':'.($updatedLabel !== '' ? $updatedLabel : $eventType),
                'generated_at' => now(),
                'source_data' => [
                    'thought_id' => $thought->id,
                    'issue_key' => $issueKey,
                    'jira_event_type' => $eventType,
                    'jira_url' => $url !== '' ? $url : null,
                ],
            ];
        }

        $limit = max(1, (int) config('pulse.max_jira', 15));

        return array_slice($payloads, 0, $limit);
    }
}

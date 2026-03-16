<?php

namespace App\Services;

use App\Exceptions\InvalidJiraCredentialsException;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class JiraSyncService
{
    /**
     * Verify that the given Jira credentials work by calling GET /rest/api/3/myself.
     *
     * @throws InvalidJiraCredentialsException On 401/403
     */
    public function validateCredentials(string $baseUrl, string $email, string $token): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '' || $email === '' || $token === '') {
            throw new InvalidJiraCredentialsException('Jira site URL, email, and API token are required.');
        }
        $response = Http::withBasicAuth($email, $token)
            ->accept('application/json')
            ->timeout(15)
            ->get("{$baseUrl}/rest/api/3/myself");
        if ($response->status() === 401 || $response->status() === 403) {
            throw new InvalidJiraCredentialsException('Invalid Jira credentials. Please check your site URL, email, and API token.');
        }
        $response->throw();
    }

    /**
     * Fetch Jira activity events for the user (issues created/updated/commented) for the last N days.
     * Returns array of event arrays suitable for creating thoughts: jira_event_id, content, metadata, source_metadata.
     *
     * @return array<int, array{jira_event_id: string, content: string, metadata: array{type: string, tags: array<int, string>}, source_metadata: array<string, mixed>}>
     *
     * @throws InvalidJiraCredentialsException On 401/403
     */
    public function fetchEvents(User $user, int $days = 14): array
    {
        $credential = $user->jiraCredential;
        if ($credential === null) {
            return [];
        }

        $baseUrl = rtrim($credential->jira_site_url, '/');
        $email = $credential->jira_email ?? $user->email;
        $token = $credential->jira_api_token; // decrypted by encrypted cast

        if (empty($baseUrl) || empty($email) || empty($token)) {
            return [];
        }

        $client = fn () => Http::withBasicAuth($email, $token)
            ->accept('application/json')
            ->timeout(30);

        // Current user's accountId for filtering changelog/comments
        $myself = $client()->get("{$baseUrl}/rest/api/3/myself");
        if ($myself->status() === 401 || $myself->status() === 403) {
            throw new InvalidJiraCredentialsException('Invalid Jira credentials. Please check your API token and site URL in settings.');
        }
        $myself->throw();
        $accountId = $myself->json('accountId');

        $jql = sprintf(
            '(reporter = currentUser() OR assignee = currentUser()) AND updated >= -%dd',
            $days
        );
        // Use the current search/jql endpoint (legacy /rest/api/3/search returns 410 Gone)
        $search = $client()->get("{$baseUrl}/rest/api/3/search/jql", [
            'jql' => $jql,
            'maxResults' => 100,
            'fields' => 'summary,project,created,updated',
        ]);
        $search->throw();
        // Response may use "values" (new API) or "issues" (legacy); support both
        $issues = $search->json('values') ?? $search->json('issues') ?? [];

        $events = [];
        foreach ($issues as $issue) {
            $key = $issue['key'] ?? null;
            $issueId = $issue['id'] ?? null;
            $fields = $issue['fields'] ?? [];
            $summary = $fields['summary'] ?? $key;
            $projectKey = $fields['project']['key'] ?? 'unknown';
            $projectName = $fields['project']['name'] ?? null;
            $projectTags = $this->projectTags($projectKey, $projectName);
            $created = $fields['created'] ?? null;
            $updated = $fields['updated'] ?? $created;
            $issueLink = "{$baseUrl}/browse/{$key}";

            if ($key === null) {
                continue;
            }

            // Created event
            $createdEventId = "{$key}:created:" . ($created ?? $key);
            $events[] = $this->event(
                $createdEventId,
                "Created {$key}: {$summary}",
                $projectTags,
                $key,
                $summary,
                $projectKey,
                $projectName,
                'created',
                $created ?? $updated,
                $issueLink
            );

            // Changelog (updated events)
            $issueDetail = $client()->get("{$baseUrl}/rest/api/3/issue/{$key}", ['expand' => 'changelog']);
            $issueDetail->throw();
            $changelog = $issueDetail->json('changelog.histories', []);
            foreach ($changelog as $history) {
                $authorId = $history['author']['accountId'] ?? null;
                if ($authorId !== $accountId) {
                    continue;
                }
                $historyId = $history['id'] ?? uniqid('', true);
                $createdAt = $history['created'] ?? $updated;
                $items = $history['items'] ?? [];
                $descriptions = [];
                foreach ($items as $item) {
                    $field = $item['field'] ?? '';
                    $from = $item['fromString'] ?? '';
                    $to = $item['toString'] ?? '';
                    $descriptions[] = trim("{$field}: {$from} → {$to}");
                }
                $content = $key . ': ' . implode('; ', $descriptions);
                $events[] = $this->event(
                    "{$key}:changelog:{$historyId}",
                    $content,
                    $projectTags,
                    $key,
                    $summary,
                    $projectKey,
                    $projectName,
                    'updated',
                    $createdAt,
                    $issueLink
                );
            }

            // Comments by current user
            $commentsRes = $client()->get("{$baseUrl}/rest/api/3/issue/{$key}/comment");
            $commentsRes->throw();
            $comments = $commentsRes->json('comments', []);
            foreach ($comments as $comment) {
                $commentAuthorId = $comment['author']['accountId'] ?? null;
                if ($commentAuthorId !== $accountId) {
                    continue;
                }
                $commentId = $comment['id'] ?? uniqid('', true);
                $body = $comment['body'] ?? '';
                if (is_array($body)) {
                    $body = $body['content'][0]['content'][0]['text'] ?? json_encode($body);
                }
                $commentCreated = $comment['created'] ?? $updated;
                $preview = mb_strlen($body) > 80 ? mb_substr($body, 0, 77) . '...' : $body;
                $events[] = $this->event(
                    "{$key}:comment:{$commentId}",
                    "Commented on {$key}: {$preview}",
                    $projectTags,
                    $key,
                    $summary,
                    $projectKey,
                    $projectName,
                    'comment',
                    $commentCreated,
                    "{$issueLink}#comment-{$commentId}"
                );
            }
        }

        return $events;
    }

    /**
     * Build tags for a Jira project: jira, project key (lowercase), and slugified project name if present.
     *
     * @return array<int, string>
     */
    private function projectTags(string $projectKey, ?string $projectName): array
    {
        $tags = ['jira', mb_strtolower(trim($projectKey))];
        if ($projectName !== null && trim($projectName) !== '') {
            $slug = mb_strtolower(trim(preg_replace('/\s+/', '_', $projectName), '_'));
            if ($slug !== '' && ! in_array($slug, $tags, true)) {
                $tags[] = $slug;
            }
        }

        return $tags;
    }

    /**
     * @param  array<int, string>  $projectTags
     * @return array{jira_event_id: string, content: string, metadata: array{type: string, tags: array<int, string>}, source_metadata: array<string, mixed>}
     */
    private function event(
        string $jiraEventId,
        string $content,
        array $projectTags,
        string $issueKey,
        string $issueSummary,
        string $projectKey,
        ?string $projectName,
        string $eventType,
        ?string $at,
        string $link
    ): array {
        $tags = $projectTags;
        $normalized = Thought::normalizeMetadataTags(['tags' => $tags]);
        $tags = $normalized['tags'] ?? $tags;

        return [
            'jira_event_id' => $jiraEventId,
            'content' => $content,
            'metadata' => [
                'type' => 'jira',
                'tags' => $tags,
            ],
            'source_metadata' => array_filter([
                'jira_event_id' => $jiraEventId,
                'jira_issue_key' => $issueKey,
                'jira_issue_summary' => $issueSummary,
                'jira_project_key' => $projectKey,
                'jira_project_name' => $projectName !== null && trim($projectName) !== '' ? trim($projectName) : null,
                'jira_event_type' => $eventType,
                'jira_updated_at' => $at,
                'jira_link' => $link,
            ], fn ($v) => $v !== null),
        ];
    }
}

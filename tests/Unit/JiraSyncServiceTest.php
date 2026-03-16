<?php

namespace Tests\Unit;

use App\Exceptions\InvalidJiraCredentialsException;
use App\Models\User;
use App\Models\UserJiraCredential;
use App\Services\JiraSyncService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JiraSyncServiceTest extends TestCase
{
    #[Test]
    public function fetch_events_returns_empty_array_when_user_has_no_jira_credential(): void
    {
        $user = User::factory()->create();
        $service = new JiraSyncService;

        $events = $service->fetchEvents($user, 14);

        $this->assertSame([], $events);
    }

    #[Test]
    public function fetch_events_returns_events_with_required_shape_when_http_mocked(): void
    {
        Http::fake([
            '*rest/api/3/myself' => Http::response(['accountId' => 'acc-123'], 200),
            '*rest/api/3/search*' => Http::response([
                'issues' => [
                    [
                        'key' => 'PROJ-1',
                        'id' => '10001',
                        'fields' => [
                            'summary' => 'Test issue',
                            'project' => ['key' => 'PROJ'],
                            'created' => '2026-01-01T10:00:00.000+0000',
                            'updated' => '2026-01-01T10:00:00.000+0000',
                        ],
                    ],
                ],
            ], 200),
            '*rest/api/3/issue/PROJ-1*' => Http::response(['changelog' => ['histories' => []]], 200),
            '*rest/api/3/issue/PROJ-1/comment' => Http::response(['comments' => []], 200),
        ]);

        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret-token',
            'jira_email' => null,
        ]);

        $service = new JiraSyncService;
        $events = $service->fetchEvents($user, 14);

        $this->assertGreaterThanOrEqual(1, count($events), 'At least the created event');
        foreach ($events as $event) {
            $this->assertArrayHasKey('jira_event_id', $event);
            $this->assertArrayHasKey('content', $event);
            $this->assertArrayHasKey('metadata', $event);
            $this->assertArrayHasKey('source_metadata', $event);
            $this->assertIsString($event['content']);
            $this->assertSame('jira', $event['metadata']['type'] ?? null);
            $this->assertIsArray($event['metadata']['tags'] ?? null);
            $this->assertContains('jira', $event['metadata']['tags']);
            $this->assertSame($event['jira_event_id'], $event['source_metadata']['jira_event_id'] ?? null);
        }
    }

    #[Test]
    public function fetch_events_throws_invalid_jira_credentials_exception_on_401(): void
    {
        Http::fake([
            '*rest/api/3/myself' => Http::response([], 401),
        ]);

        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'bad-token',
        ]);

        $service = new JiraSyncService;

        $this->expectException(InvalidJiraCredentialsException::class);
        $this->expectExceptionMessage('Invalid Jira credentials');

        $service->fetchEvents($user, 14);
    }

    #[Test]
    public function validate_credentials_throws_on_401(): void
    {
        Http::fake(['*rest/api/3/myself' => Http::response([], 401)]);
        $service = new JiraSyncService;

        $this->expectException(InvalidJiraCredentialsException::class);
        $this->expectExceptionMessage('Invalid Jira credentials');

        $service->validateCredentials('https://example.atlassian.net', 'dev@example.com', 'bad-token');
    }

    #[Test]
    public function validate_credentials_succeeds_on_200(): void
    {
        Http::fake(['*rest/api/3/myself' => Http::response(['accountId' => 'acc-1'], 200)]);
        $service = new JiraSyncService;

        $service->validateCredentials('https://example.atlassian.net', 'dev@example.com', 'secret');

        $this->addToAssertionCount(1);
    }
}

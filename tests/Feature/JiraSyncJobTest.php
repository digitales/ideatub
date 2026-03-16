<?php

namespace Tests\Feature;

use App\Jobs\SyncUserJiraActivity;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserJiraCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraSyncJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openrouter.api_key', 'test-key');
    }

    private function fakeJiraAndOpenRouter(): void
    {
        $embedding = array_fill(0, 1536, 0.01);
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
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'data' => [['embedding' => $embedding]],
            ], 200),
        ]);
    }

    public function test_job_creates_thought_with_jira_type_and_tags(): void
    {
        $this->fakeJiraAndOpenRouter();
        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret',
        ]);

        $job = new SyncUserJiraActivity($user->id, 14);
        $job->handle(app(\App\Services\JiraSyncService::class), app(\App\Services\OpenRouterService::class));

        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::where('user_id', $user->id)->where('source', 'jira')->first();
        $this->assertNotNull($thought);
        $this->assertSame('jira', $thought->metadata['type'] ?? null);
        $this->assertIsArray($thought->metadata['tags'] ?? null);
        $this->assertContains('jira', $thought->metadata['tags']);
        $this->assertContains('proj', $thought->metadata['tags']);
    }

    public function test_job_idempotency_does_not_duplicate_thoughts(): void
    {
        $this->fakeJiraAndOpenRouter();
        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret',
        ]);

        $job = new SyncUserJiraActivity($user->id, 14);
        $jiraSync = app(\App\Services\JiraSyncService::class);
        $openRouter = app(\App\Services\OpenRouterService::class);

        $job->handle($jiraSync, $openRouter);
        $this->assertDatabaseCount('thoughts', 1);

        $job->handle($jiraSync, $openRouter);
        $this->assertDatabaseCount('thoughts', 1);
    }
}

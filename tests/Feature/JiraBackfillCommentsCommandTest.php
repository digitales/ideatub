<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserJiraCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraBackfillCommentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_updates_existing_jira_comment_thought_content(): void
    {
        Http::fake([
            '*rest/api/3/myself' => Http::response(['accountId' => 'acc-123'], 200),
            '*rest/api/3/search*' => Http::response([
                'issues' => [[
                    'key' => 'PROJ-1',
                    'id' => '10001',
                    'fields' => [
                        'summary' => 'Issue summary',
                        'project' => ['key' => 'PROJ'],
                        'created' => '2026-03-10T10:00:00.000+0000',
                        'updated' => '2026-03-10T10:00:00.000+0000',
                    ],
                ]],
            ], 200),
            '*rest/api/3/issue/PROJ-1/comment*' => Http::response([
                'comments' => [[
                    'id' => '90001',
                    'created' => '2026-03-10T10:05:00.000+0000',
                    'author' => ['accountId' => 'acc-123'],
                    'body' => [
                        'type' => 'doc',
                        'version' => 1,
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [
                                ['type' => 'text', 'text' => 'This is full comment text'],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
            '*rest/api/3/issue/PROJ-1*' => Http::response(['changelog' => ['histories' => []]], 200),
        ]);

        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret',
        ]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'jira',
            'content' => 'Commented on PROJ-1: {"type":"doc","version":1,"content":[{"type":"paragraph"...',
            'source_metadata' => [
                'jira_event_id' => 'PROJ-1:comment:90001',
                'jira_event_type' => 'comment',
                'jira_issue_key' => 'PROJ-1',
            ],
        ]);

        $this->artisan('jira:backfill-comments', ['--user-id' => $user->id, '--days' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain('Updated 1 thought(s)');

        $thought->refresh();
        $this->assertStringContainsString('This is full comment text', $thought->content);
        $this->assertStringNotContainsString('{"type":"doc"', $thought->content);
    }
}

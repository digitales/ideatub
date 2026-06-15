<?php

namespace Tests\Unit\Services\Attention;

use App\Models\Thought;
use App\Models\User;
use App\Services\Attention\JiraActivityQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraActivityQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_jira_thought_returns_issue_row_with_external_url(): void
    {
        $user = User::factory()->create();

        Thought::factory()->for($user)->create([
            'source' => 'jira',
            'source_metadata' => [
                'jira_issue_key' => 'IDEA-42',
                'jira_summary' => 'Pulse dashboard',
                'jira_url' => 'https://example.atlassian.net/browse/IDEA-42',
                'jira_updated_at' => now()->subDay()->toIso8601String(),
                'jira_event_type' => 'updated',
            ],
        ]);

        $items = app(JiraActivityQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame('jira_issue', $items[0]->kind);
        $this->assertStringContainsString('IDEA-42', $items[0]->title);
        $this->assertSame('https://example.atlassian.net/browse/IDEA-42', $items[0]->href);
    }
}

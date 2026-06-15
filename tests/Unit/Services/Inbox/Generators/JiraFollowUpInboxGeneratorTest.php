<?php

namespace Tests\Unit\Services\Inbox\Generators;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Generators\JiraFollowUpInboxGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraFollowUpInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_jira_update_produces_follow_up_inbox_item(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        Thought::factory()->for($user)->create([
            'source' => 'jira',
            'source_metadata' => [
                'jira_issue_key' => 'IDEA-99',
                'jira_summary' => 'Review pulse PR',
                'jira_url' => 'https://example.atlassian.net/browse/IDEA-99',
                'jira_updated_at' => now()->subHours(2)->toIso8601String(),
                'jira_event_type' => 'comment',
            ],
        ]);

        $payloads = app(JiraFollowUpInboxGenerator::class)->generate($user);

        $this->assertCount(1, $payloads);
        $this->assertSame('jira_follow_up', $payloads[0]['generator_type']);
        $this->assertStringContainsString('IDEA-99', $payloads[0]['title']);
        $this->assertStringContainsString('https://example.atlassian.net/browse/IDEA-99', $payloads[0]['body']);
    }
}

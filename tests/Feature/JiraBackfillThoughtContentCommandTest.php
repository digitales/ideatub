<?php

namespace Tests\Feature;

use App\Models\Thought;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraBackfillThoughtContentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_legacy_row_updates_to_normalized_content(): void
    {
        $thought = Thought::factory()->create([
            'source' => 'jira',
            'content' => 'Commented on PROJ-101: Please include rollout notes',
            'source_metadata' => [
                'jira_issue_key' => 'PROJ-101',
                'jira_issue_summary' => 'Add onboarding checklist',
                'jira_event_type' => 'comment',
            ],
        ]);

        $this->artisan('jira:backfill-thought-content')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated: 1')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Unchanged: 0');

        $thought->refresh();

        $this->assertSame(
            'PROJ-101: Add onboarding checklist - Commented: Please include rollout notes',
            $thought->content
        );
    }

    public function test_missing_key_or_summary_row_is_skipped(): void
    {
        $thought = Thought::factory()->create([
            'source' => 'jira',
            'content' => 'Created PROJ-202',
            'source_metadata' => [
                'jira_issue_key' => 'PROJ-202',
                'jira_event_type' => 'created',
            ],
        ]);

        $this->artisan('jira:backfill-thought-content')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Skipped: 1')
            ->expectsOutputToContain('Unchanged: 0');

        $thought->refresh();

        $this->assertSame('Created PROJ-202', $thought->content);
    }

    public function test_dry_run_makes_no_writes(): void
    {
        $thought = Thought::factory()->create([
            'source' => 'jira',
            'content' => 'Created PROJ-303',
            'source_metadata' => [
                'jira_issue_key' => 'PROJ-303',
                'jira_issue_summary' => 'Launch internal alpha',
                'jira_event_type' => 'created',
            ],
        ]);

        $originalUpdatedAt = $thought->updated_at;

        $this->artisan('jira:backfill-thought-content', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run complete. No writes performed.')
            ->expectsOutputToContain('Updated: 1')
            ->expectsOutputToContain('Unchanged: 0');

        $thought->refresh();

        $this->assertSame('Created PROJ-303', $thought->content);
        $this->assertTrue($thought->updated_at->equalTo($originalUpdatedAt));
    }

    public function test_already_normalized_content_stays_unchanged_and_is_reported(): void
    {
        $thought = Thought::factory()->create([
            'source' => 'jira',
            'content' => 'PROJ-404: Keep release notes visible - Updated',
            'source_metadata' => [
                'jira_issue_key' => 'PROJ-404',
                'jira_issue_summary' => 'Keep release notes visible',
                'jira_event_type' => 'updated',
            ],
        ]);

        $originalUpdatedAt = $thought->updated_at;

        $this->artisan('jira:backfill-thought-content')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Unchanged: 1');

        $thought->refresh();

        $this->assertSame('PROJ-404: Keep release notes visible - Updated', $thought->content);
        $this->assertTrue($thought->updated_at->equalTo($originalUpdatedAt));
    }
}

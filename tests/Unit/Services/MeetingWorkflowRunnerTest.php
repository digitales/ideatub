<?php

namespace Tests\Unit\Services;

use App\Models\MeetingRun;
use App\Models\Thought;
use App\Services\Meetings\MeetingWorkflowRunner;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingWorkflowRunnerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function runner_marks_run_complete_and_links_final_meeting_analysis_thought(): void
    {
        $run = MeetingRun::factory()->create(['status' => 'queued']);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andReturn(json_encode([
                    'summary' => 'Team aligned on sprint goals.',
                    'core_categories' => [
                        'decisions' => ['Ship reporting MVP'],
                        'action_items' => [
                            [
                                'task' => 'Draft release checklist',
                                'owner' => 'Alex',
                                'due_date' => '2026-04-20',
                                'confidence' => 'high',
                            ],
                        ],
                        'risks' => ['Dependency on API partner'],
                        'blockers' => [],
                        'follow_ups' => ['Review API contract'],
                    ],
                    'custom_sections' => [
                        'budget' => ['No new spend approved'],
                    ],
                    'requested_sections' => [
                        'summary' => 'Done',
                    ],
                ], JSON_UNESCAPED_SLASHES));
        });

        app(MeetingWorkflowRunner::class)->run($run->fresh());

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->final_meeting_thought_id);
        $this->assertSame(1, $run->current_stage);

        $analysis = Thought::query()->find($run->final_meeting_thought_id);
        $this->assertNotNull($analysis);
        $this->assertSame('meeting_analysis', $analysis->metadata['type'] ?? null);
        $this->assertSame($run->meeting_thought_id, $analysis->metadata['meeting_id'] ?? null);
        $tags = $analysis->metadata['tags'] ?? [];
        $this->assertContains('meeting_analysis', $tags);
    }

    #[Test]
    public function runner_marks_failed_on_error(): void
    {
        $run = MeetingRun::factory()->create(['status' => 'queued']);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andThrow(new \RuntimeException('OpenRouter unavailable'));
        });

        app(MeetingWorkflowRunner::class)->run($run->fresh());

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->final_meeting_thought_id);
        $this->assertStringContainsString('OpenRouter unavailable', (string) $run->error_summary);
    }

    #[Test]
    public function runner_rejects_non_meeting_brief_workflow(): void
    {
        $run = MeetingRun::factory()->create([
            'status' => 'queued',
            'workflow_type_snapshot' => 'something_else',
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchFromPrompt');
        });

        $this->expectException(InvalidArgumentException::class);
        app(MeetingWorkflowRunner::class)->run($run->fresh());
    }
}

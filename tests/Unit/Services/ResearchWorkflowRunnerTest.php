<?php

namespace Tests\Unit\Services;

use App\Models\ResearchRun;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Research\ResearchWorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResearchWorkflowRunnerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function runner_marks_run_complete_and_links_final_research_thought(): void
    {
        $run = ResearchRun::factory()->create(['status' => 'queued']);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andReturn("## Summary\nUseful answer\n\n## Next steps\n- Interview users");
        });

        app(ResearchWorkflowRunner::class)->run($run->fresh());

        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->final_research_thought_id);
        $this->assertSame(1, $run->current_stage);

        $research = Thought::query()->find($run->final_research_thought_id);
        $this->assertNotNull($research);
        $this->assertSame('research', $research->metadata['type'] ?? null);
        $this->assertSame($run->idea_thought_id, $research->metadata['idea_id'] ?? null);
    }

    #[Test]
    public function runner_marks_failed_on_error_and_does_not_replace_or_mutate_existing_research(): void
    {
        $run = ResearchRun::factory()->create(['status' => 'queued']);
        $ideaId = $run->idea_thought_id;
        $existingResearch = Thought::factory()->create([
            'user_id' => $run->user_id,
            'content' => 'Existing linked research body',
            'metadata' => [
                'type' => 'research',
                'idea_id' => $ideaId,
                'tags' => ['research'],
            ],
            'source_metadata' => ['note' => 'keep me unchanged'],
        ]);

        $before = Thought::researchForIdea($ideaId)->get();

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andThrow(new \RuntimeException('OpenRouter unavailable'));
        });

        app(ResearchWorkflowRunner::class)->run($run->fresh());

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->current_stage);
        $this->assertStringContainsString('OpenRouter unavailable', (string) $run->error_summary);
        $this->assertNull($run->final_research_thought_id);
        $after = Thought::researchForIdea($ideaId)->get();
        $this->assertCount($before->count(), $after);

        $existingResearch->refresh();
        $this->assertTrue($after->sole()->is($existingResearch));
        $this->assertSame('Existing linked research body', $existingResearch->content);
        $this->assertSame(['note' => 'keep me unchanged'], $existingResearch->source_metadata);
    }

    #[Test]
    public function runner_does_not_continue_cancelled_run_execution(): void
    {
        $run = ResearchRun::factory()->create([
            'status' => 'cancelled',
            'current_stage' => 0,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchFromPrompt');
        });

        app(ResearchWorkflowRunner::class)->run($run->fresh());

        $run->refresh();

        $this->assertSame('cancelled', $run->status);
        $this->assertSame(0, $run->current_stage);
        $this->assertNull($run->final_research_thought_id);
        $this->assertSame(0, Thought::query()->where('metadata->type', 'research')->count());
    }

    #[Test]
    public function runner_rejects_non_quick_brief_workflow(): void
    {
        $run = ResearchRun::factory()->create([
            'status' => 'queued',
            'workflow_type_snapshot' => 'deep_research',
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchFromPrompt');
        });

        $this->expectException(InvalidArgumentException::class);

        app(ResearchWorkflowRunner::class)->run($run->fresh());
    }

    #[Test]
    public function runner_includes_only_newest_linked_research_in_prompt(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $older = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'OLDER RESEARCH BODY',
            'metadata' => [
                'type' => 'research',
                'idea_id' => $idea->id,
                'tags' => ['research'],
            ],
        ]);
        $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'NEWER RESEARCH BODY',
            'metadata' => [
                'type' => 'research',
                'idea_id' => $idea->id,
                'tags' => ['research'],
            ],
        ]);

        $run = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'status' => 'queued',
            'context_options_snapshot' => ['idea', 'existing_research'],
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->with(\Mockery::on(function (string $prompt): bool {
                    return str_contains($prompt, 'NEWER RESEARCH BODY')
                        && ! str_contains($prompt, 'OLDER RESEARCH BODY');
                }))
                ->andReturn('## Summary\nDone.');
        });

        app(ResearchWorkflowRunner::class)->run($run->fresh());

        $run->refresh();
        $this->assertSame('completed', $run->status);
    }
}

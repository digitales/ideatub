<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use App\Services\WorkingMemory\WorkingMemoryScopeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAssemblerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function assemble_payload_partitions_question_thoughts_into_open_questions_only(): void
    {
        $user = User::factory()->create();

        $withQuestion = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'What is the rollout date?',
            'created_at' => now()->subMinutes(2),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Deployment checklist item one.',
            'created_at' => now()->subMinute(),
        ]);

        $assembler = new WorkingMemoryAssembler(
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        );

        $thoughts = Thought::query()->where('user_id', $user->id)->orderByDesc('created_at')->get();
        $payload = $assembler->assemblePayload($thoughts);

        $questionIds = collect($payload['open_questions'])->pluck('thought_id')->filter()->all();
        $this->assertContains((string) $withQuestion->id, $questionIds);

        $threadIds = collect($payload['active_threads'])->pluck('thought_id')->filter()->all();
        $this->assertNotContains((string) $withQuestion->id, $threadIds);
    }

    #[Test]
    public function render_summary_emits_markdown_links_when_thought_ids_present(): void
    {
        $assembler = new WorkingMemoryAssembler(
            app(WorkingMemoryScopeNormalizer::class),
            app(WorkingMemoryConsolidationWindowResolver::class),
        );

        $tid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $markdown = $assembler->renderSummary([
            'executive_summary' => 'Summary.',
            'key_concepts' => [['title' => 'Concept']],
            'active_threads' => [['title' => 'Thread line', 'thought_id' => $tid]],
            'open_questions' => [['question' => 'Q?', 'thought_id' => $tid]],
            'next_actions' => [['action' => 'Act', 'thought_id' => $tid]],
            'confidence_score' => 50.0,
        ]);

        $this->assertStringContainsString('[Thread line]', $markdown);
        $this->assertStringContainsString('/thoughts/'.$tid, $markdown);
        $this->assertStringContainsString('[Q?]', $markdown);
        $this->assertStringContainsString('[Act]', $markdown);
    }
}

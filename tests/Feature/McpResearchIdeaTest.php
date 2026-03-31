<?php

namespace Tests\Feature;

use App\Jobs\RunResearchRun;
use App\Models\ResearchRun;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class McpResearchIdeaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: User}
     */
    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('m', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    public function test_research_idea_with_content_queues_run_returns_idea_and_run_ids_without_research_thought(): void
    {
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldNotReceive('researchNote');
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['content' => 'MCP queued research idea'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.idea_id', fn ($id) => is_string($id) && strlen($id) > 0);
        $response->assertJsonPath('result.research_run_id', fn ($id) => is_int($id) && $id > 0);
        $response->assertJsonPath('result.research_id', null);

        $ideaId = $response->json('result.idea_id');
        $runId = $response->json('result.research_run_id');

        $idea = Thought::find($ideaId);
        $this->assertNotNull($idea);
        $this->assertSame($user->id, $idea->user_id);
        $this->assertSame('idea', $idea->metadata['type'] ?? null);

        $run = ResearchRun::find($runId);
        $this->assertNotNull($run);
        $this->assertSame($ideaId, $run->idea_thought_id);
        $this->assertSame('mcp', $run->source);
        $this->assertSame('queued', $run->status);

        $this->assertSame(0, Thought::where('metadata->type', 'research')->count());

        Bus::assertDispatched(RunResearchRun::class, fn (RunResearchRun $job) => $job->researchRunId === $runId);
    }

    public function test_research_idea_with_idea_id_queues_run_returns_run_id(): void
    {
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Existing idea for MCP queue',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchNote');
            $mock->shouldNotReceive('embed');
            $mock->shouldNotReceive('extractMetadata');
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['idea_id' => $idea->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.idea_id', $idea->id);
        $response->assertJsonPath('result.research_run_id', fn ($id) => is_int($id) && $id > 0);
        $response->assertJsonPath('result.research_id', null);

        $runId = $response->json('result.research_run_id');
        $run = ResearchRun::find($runId);
        $this->assertNotNull($run);
        $this->assertSame($idea->id, $run->idea_thought_id);
        $this->assertSame('queued', $run->status);

        Bus::assertDispatched(RunResearchRun::class, fn (RunResearchRun $job) => $job->researchRunId === $runId);
    }

    public function test_research_idea_second_call_reuses_active_run_and_dispatches_job_once(): void
    {
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Idea with one active run',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchNote');
        });

        $first = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['idea_id' => $idea->id],
        ]);
        $first->assertStatus(200);
        $runId = $first->json('result.research_run_id');

        $second = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'research_idea',
            'params' => ['idea_id' => $idea->id],
        ]);
        $second->assertStatus(200);
        $this->assertSame($runId, $second->json('result.research_run_id'));

        $this->assertSame(1, ResearchRun::query()->where('idea_thought_id', $idea->id)->count());
        Bus::assertDispatchedTimes(RunResearchRun::class, 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_research_for_existing_idea_creates_linked_research_thought(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Build a small SaaS for vehicle analytics',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $researchText = 'Key considerations: market size, MVP scope. Next steps: validate with users, pick stack.';
        $this->mock(OpenRouterService::class, function ($mock) use ($researchText): void {
            $mock->shouldReceive('researchNote')
                ->once()
                ->with('Build a small SaaS for vehicle analytics')
                ->andReturn($researchText);
        });

        $service = app(ResearchService::class);
        $research = $service->runResearchForIdea($idea, 'web');

        $this->assertInstanceOf(Thought::class, $research);
        $this->assertSame($researchText, $research->content);
        $this->assertSame('research', $research->metadata['type']);
        $this->assertSame($idea->id, $research->metadata['idea_id']);
        $this->assertSame((string) $idea->user_id, (string) $research->user_id);
        $this->assertSame('web', $research->source);
        $this->assertNull($research->embedding);

        $found = Thought::researchForIdea($idea->id)->first();
        $this->assertNotNull($found);
        $this->assertSame($research->id, $found->id);
    }

    public function test_create_idea_and_research_creates_idea_then_linked_research(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $researchText = 'Research note: validate demand, then build MVP.';
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding, $researchText): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldReceive('researchNote')
                ->once()
                ->with('Ship a side project this quarter')
                ->andReturn($researchText);
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $result = $service->createIdeaAndResearch('Ship a side project this quarter', 'web');

        $this->assertArrayHasKey('idea', $result);
        $this->assertArrayHasKey('research', $result);
        $idea = $result['idea'];
        $research = $result['research'];

        $this->assertInstanceOf(Thought::class, $idea);
        $this->assertSame('idea', $idea->metadata['type']);
        $this->assertSame('Ship a side project this quarter', $idea->getDecodedContent());

        $this->assertInstanceOf(Thought::class, $research);
        $this->assertSame('research', $research->metadata['type']);
        $this->assertSame($idea->id, $research->metadata['idea_id']);
        $this->assertSame($researchText, $research->content);
    }

    public function test_create_idea_and_research_when_research_fails_keeps_idea_returns_null_research(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldReceive('researchNote')
                ->once()
                ->andThrow(new \RuntimeException('API error'));
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $result = $service->createIdeaAndResearch('An idea that research will fail for', 'mcp');

        $this->assertArrayHasKey('idea', $result);
        $this->assertArrayHasKey('research', $result);
        $this->assertInstanceOf(Thought::class, $result['idea']);
        $this->assertSame('idea', $result['idea']->metadata['type']);
        $this->assertNull($result['research']);

        $researchCount = Thought::where('metadata->type', 'research')->count();
        $this->assertSame(0, $researchCount);
    }
}

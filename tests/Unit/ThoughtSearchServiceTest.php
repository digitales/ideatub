<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\ThoughtSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_tag_matches_first_then_semantic(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $tagThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Unrelated content',
            'embedding' => array_fill(0, 1536, 0.5),
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);
        $semanticThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec is important',
            'embedding' => $embedding,
            'metadata' => ['tags' => []],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $service = app(ThoughtSearchService::class);
        $result = $service->search('decision:project-spec', $user->id, [
            'limit' => 10,
            'max_distance' => 0.6,
            'tag_limit' => 50,
            'semantic_limit' => 50,
        ]);

        $this->assertCount(2, $result['thoughts']);
        $this->assertSame(2, $result['total']);
        $ids = $result['thoughts']->pluck('id')->all();
        $this->assertSame($tagThought->id, $ids[0], 'Tag-matched thought should be first');
        $this->assertSame($semanticThought->id, $ids[1]);
    }

    public function test_search_dedupes_semantic_results_that_also_match_tag(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec content',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($embedding);
        });

        $service = app(ThoughtSearchService::class);
        $result = $service->search('decision:project-spec', $user->id, [
            'limit' => 10,
            'max_distance' => 0.6,
            'tag_limit' => 50,
            'semantic_limit' => 50,
        ]);

        $this->assertCount(1, $result['thoughts']);
        $this->assertSame(1, $result['total']);
    }
}

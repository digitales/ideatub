<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchIncludeTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_with_exact_tag_returns_tagged_thought_first(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);

        $tagThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Content A',
            'embedding' => array_fill(0, 1536, 0.9),
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);
        $otherThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Project spec is cool',
            'embedding' => $embedding,
            'metadata' => ['tags' => []],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'decision:project-spec']));

        $response->assertStatus(200);
        $thoughtIds = $response->viewData('thoughts')->items();
        $ids = array_map(fn ($t) => $t->id, $thoughtIds);
        $this->assertContains($tagThought->id, $ids);
        $this->assertSame($tagThought->id, $ids[0], 'Tag-matched thought should be first');
    }

    public function test_search_with_tag_substring_returns_matching_thought(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('project-spec')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'project-spec']));

        $response->assertStatus(200);
        $thoughts = $response->viewData('thoughts')->items();
        $this->assertCount(1, $thoughts);
        $this->assertSame('Some content', $thoughts[0]->content);
    }

    public function test_search_with_no_tag_match_returns_semantic_only(): void
    {
        $user = User::factory()->create();
        $embedding = array_fill(0, 1536, 0.01);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Semantic content about pgvector',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['work']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('pgvector')->andReturn($embedding);
        });

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pgvector']));

        $response->assertStatus(200);
        $thoughts = $response->viewData('thoughts')->items();
        $this->assertCount(1, $thoughts);
        $this->assertSame('Semantic content about pgvector', $thoughts[0]->content);
    }
}

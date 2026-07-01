<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtSemanticGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_semantic_graph_returns_focal_only_when_no_embedding(): void
    {
        config(['features.memory_graph_semantic' => true]);

        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id, 'embedding' => null]);

        $response = $this->actingAs($user)->getJson(route('thoughts.semantic_graph.data', $focal));

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'semantic');
        $response->assertJsonPath('meta.error', 'no_embedding');
        $response->assertJsonCount(1, 'nodes');
    }

    public function test_semantic_graph_404_when_flag_off(): void
    {
        config(['features.memory_graph_semantic' => false]);

        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('thoughts.semantic_graph', $focal))
            ->assertNotFound();
    }
}

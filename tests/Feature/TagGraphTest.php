<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_tag_graph_returns_hub_and_tagged_thoughts_when_flag_on(): void
    {
        config(['features.memory_graph_tag' => true]);

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Tagged thought',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->actingAs($user)->getJson(route('graph.tags.data', ['tag' => 'work']));

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'tag');
        $response->assertJsonCount(2, 'nodes');
        $response->assertJsonCount(1, 'edges');
    }

    public function test_tag_graph_404_when_flag_off(): void
    {
        config(['features.memory_graph_tag' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('graph.tags', ['tag' => 'work']))
            ->assertNotFound();
    }
}

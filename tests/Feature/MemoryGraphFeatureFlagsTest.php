<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryGraphFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_project_graph_returns_404_when_feature_disabled(): void
    {
        config(['features.memory_graph_project' => false]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.graph', $project))
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson(route('projects.graph.data', $project))
            ->assertNotFound();
    }

    public function test_project_graph_accessible_when_feature_enabled(): void
    {
        config(['features.memory_graph_project' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.graph', $project))
            ->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use Tests\TestCase;

class ProjectGraphEnhancedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_project_graph_filters_by_link_type_query_param(): void
    {
        config(['features.memory_graph_project' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $a = Thought::factory()->create(['user_id' => $user->id]);
        $b = Thought::factory()->create(['user_id' => $user->id]);
        $c = Thought::factory()->create(['user_id' => $user->id]);
        $project->thoughts()->attach([$a->id, $b->id, $c->id]);

        ThoughtLink::create([
            'user_id' => $user->id,
            'from_thought_id' => $a->id,
            'to_thought_id' => $b->id,
            'link_type' => ThoughtLinkType::RelatesTo->value,
        ]);
        ThoughtLink::create([
            'user_id' => $user->id,
            'from_thought_id' => $a->id,
            'to_thought_id' => $c->id,
            'link_type' => ThoughtLinkType::Supports->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('projects.graph.data', [
            'project' => $project,
            'link_types' => [ThoughtLinkType::RelatesTo->value],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'edges');
        $response->assertJsonPath('edges.0.to', $b->id);
    }

    public function test_project_graph_page_includes_filter_toolbar(): void
    {
        config(['features.memory_graph_project' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.graph', $project))
            ->assertOk()
            ->assertSee('project-graph-filters', false);
    }
}

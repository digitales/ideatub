<?php

namespace Tests\Feature;

use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use Tests\TestCase;

class ProjectGraphAndShareManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_graph_data_returns_nodes_and_member_only_edges(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $a = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Node A']);
        $b = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Node B']);
        $outside = Thought::factory()->create(['user_id' => $user->id]);
        $project->thoughts()->attach($a->id, ['sort_order' => 0]);
        $project->thoughts()->attach($b->id, ['sort_order' => 1]);

        ThoughtLink::create([
            'user_id' => $user->id,
            'from_thought_id' => $a->id,
            'to_thought_id' => $b->id,
            'link_type' => ThoughtLinkType::RelatesTo->value,
        ]);
        ThoughtLink::create([
            'user_id' => $user->id,
            'from_thought_id' => $a->id,
            'to_thought_id' => $outside->id,
            'link_type' => ThoughtLinkType::Supports->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('projects.graph.data', $project));

        $response->assertOk();
        $response->assertJsonCount(2, 'nodes');
        $response->assertJsonCount(1, 'edges');
        $this->assertSame($a->id, $response->json('edges.0.from'));
        $this->assertSame($b->id, $response->json('edges.0.to'));
    }

    public function test_guest_cannot_fetch_graph_data(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->getJson(route('projects.graph.data', $project))->assertUnauthorized();
    }

    public function test_owner_can_create_and_revoke_project_share(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('projects.shares.store', $project), [])
            ->assertRedirect(route('projects.shares.index', $project));

        $share = ProjectShare::query()->where('project_id', $project->id)->first();
        $this->assertNotNull($share);

        $this->actingAs($user)
            ->delete(route('project-shares.destroy', $share))
            ->assertRedirect(route('projects.shares.index', $project));

        $this->assertDatabaseMissing('project_shares', ['id' => $share->id]);
    }

    public function test_other_user_cannot_open_project_shares_index(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->get(route('projects.shares.index', $project))
            ->assertForbidden();
    }
}

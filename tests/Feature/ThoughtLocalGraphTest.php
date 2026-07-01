<?php

namespace Tests\Feature;

use App\Enums\ThoughtLinkType;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtLocalGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_local_graph_data_returns_focal_and_linked_nodes_when_flag_on(): void
    {
        config(['features.memory_graph_local' => true]);

        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Focal thought']);
        $linked = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Linked thought']);

        ThoughtLink::factory()->create([
            'user_id' => $user->id,
            'from_thought_id' => $focal->id,
            'to_thought_id' => $linked->id,
            'link_type' => ThoughtLinkType::RelatesTo->value,
        ]);

        $response = $this->actingAs($user)->getJson(route('thoughts.graph.data', $focal));

        $response->assertOk();
        $response->assertJsonPath('meta.mode', 'local');
        $response->assertJsonPath('meta.focal_id', $focal->id);
        $response->assertJsonCount(2, 'nodes');
        $response->assertJsonCount(1, 'edges');
    }

    public function test_local_graph_404_when_flag_off(): void
    {
        config(['features.memory_graph_local' => false]);

        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson(route('thoughts.graph.data', $focal))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('thoughts.graph', $focal))
            ->assertNotFound();
    }

    public function test_guest_cannot_access_local_graph(): void
    {
        config(['features.memory_graph_local' => true]);

        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id]);

        $this->getJson(route('thoughts.graph.data', $focal))->assertUnauthorized();
    }

    public function test_user_cannot_access_another_users_local_graph(): void
    {
        config(['features.memory_graph_local' => true]);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('thoughts.graph.data', $focal))
            ->assertForbidden();
    }

    public function test_thought_detail_shows_connection_graph_panel_when_flag_on(): void
    {
        config(['features.memory_graph_local' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Panel test thought']);

        $this->actingAs($user)
            ->get(route('thoughts.show', $thought))
            ->assertOk()
            ->assertSee('Connection graph', false);
    }

    public function test_thought_detail_hides_connection_graph_panel_when_flag_off(): void
    {
        config(['features.memory_graph_local' => false]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Panel test thought']);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

        $response->assertOk();
        $response->assertDontSee('Connection graph', false);
    }
}

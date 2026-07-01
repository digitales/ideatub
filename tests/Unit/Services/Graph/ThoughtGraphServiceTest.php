<?php

namespace Tests\Unit\Services\Graph;

use App\Enums\ThoughtLinkType;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use App\Services\Graph\ThoughtGraphQuery;
use App\Services\Graph\ThoughtGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_graph_collects_focal_and_one_hop_links(): void
    {
        $user = User::factory()->create();
        $focal = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Focal thought']);
        $linked = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Linked thought']);

        ThoughtLink::factory()->create([
            'user_id' => $user->id,
            'from_thought_id' => $focal->id,
            'to_thought_id' => $linked->id,
            'link_type' => ThoughtLinkType::RelatesTo->value,
        ]);

        $query = ThoughtGraphQuery::forLocal($user->id, $focal->id, ['depth' => 1]);
        $payload = app(ThoughtGraphService::class)->build($query);

        $this->assertSame('local', $payload['meta']['mode']);
        $this->assertCount(2, $payload['nodes']);
        $this->assertCount(1, $payload['edges']);
        $this->assertSame('thought_link', $payload['edges'][0]['edge_type']);
        $this->assertSame($focal->id, $payload['meta']['focal_id']);
    }

    public function test_local_graph_includes_parent_and_child_when_enabled(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Parent doc']);
        $focal = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Section',
            'parent_id' => $parent->id,
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Child section',
            'parent_id' => $focal->id,
        ]);

        $query = ThoughtGraphQuery::forLocal($user->id, $focal->id, [
            'include_parent_child' => true,
            'include_chunks' => true,
        ]);
        $payload = app(ThoughtGraphService::class)->build($query);

        $nodeIds = collect($payload['nodes'])->pluck('id');
        $this->assertTrue($nodeIds->contains($parent->id));
        $this->assertTrue($nodeIds->contains($child->id));
        $this->assertTrue(
            collect($payload['edges'])->contains(fn (array $e) => $e['edge_type'] === 'parent_child')
        );
    }

    public function test_local_graph_hides_chunks_by_default(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create(['user_id' => $user->id]);
        $focal = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
        ]);

        $query = ThoughtGraphQuery::forLocal($user->id, $focal->id, []);
        $payload = app(ThoughtGraphService::class)->build($query);

        $nodeIds = collect($payload['nodes'])->pluck('id');
        $this->assertTrue($nodeIds->contains($focal->id));
        $this->assertFalse($nodeIds->contains($parent->id));
    }
}

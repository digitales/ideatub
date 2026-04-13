<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\ProjectMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProjectMembershipServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_thought_assigns_incrementing_sort_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $a = Thought::factory()->create(['user_id' => $user->id]);
        $b = Thought::factory()->create(['user_id' => $user->id]);
        $service = new ProjectMembershipService;

        $service->addThought($project, $a);
        $service->addThought($project, $b);

        $orders = $project->thoughts()->pluck('sort_order', 'thoughts.id')->all();
        $this->assertSame(0, $orders[$a->id]);
        $this->assertSame(1, $orders[$b->id]);
    }

    public function test_add_thought_throws_when_different_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $thought = Thought::factory()->create(['user_id' => $other->id]);
        $service = new ProjectMembershipService;

        $this->expectException(InvalidArgumentException::class);
        $service->addThought($project, $thought);
    }

    public function test_reorder_updates_pivot_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $a = Thought::factory()->create(['user_id' => $user->id]);
        $b = Thought::factory()->create(['user_id' => $user->id]);
        $service = new ProjectMembershipService;
        $service->addThought($project, $a);
        $service->addThought($project, $b);

        $service->reorder($project, [$b->id, $a->id]);

        $ids = $project->thoughts()->pluck('thoughts.id')->all();
        $this->assertSame([$b->id, $a->id], $ids);
    }

    public function test_remove_normalizes_sort_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $a = Thought::factory()->create(['user_id' => $user->id]);
        $b = Thought::factory()->create(['user_id' => $user->id]);
        $c = Thought::factory()->create(['user_id' => $user->id]);
        $service = new ProjectMembershipService;
        $service->addThought($project, $a);
        $service->addThought($project, $b);
        $service->addThought($project, $c);

        $service->removeThought($project, $b);

        $orders = $project->thoughts()->pluck('sort_order', 'thoughts.id')->all();
        $this->assertSame(0, $orders[$a->id]);
        $this->assertSame(1, $orders[$c->id]);
    }
}

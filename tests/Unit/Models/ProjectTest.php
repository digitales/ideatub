<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_belongs_to_parent(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->for($user)->create([
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => null,
            'parent_project_id' => null,
        ]);
        $child = Project::factory()->for($user)->create([
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => 'foo',
            'parent_project_id' => $root->id,
        ]);

        $this->assertTrue($child->parent->is($root));
        $this->assertTrue($root->children->contains($child));
    }
}

<?php

namespace Tests\Unit\Services\Projects;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\Projects\ProjectScopeMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectScopeMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ProjectScopeMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(ProjectScopeMatcher::class);
    }

    #[Test]
    public function client_root_matches_thought_linked_to_root(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $thought = Thought::factory()->for($user)->create();
        $root->thoughts()->attach($thought->id, ['sort_order' => 1]);
        $thought->load('projects:id');

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $root->id, $root)
        );
    }

    #[Test]
    public function client_root_matches_thought_linked_only_to_child(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        $thought = Thought::factory()->for($user)->create();
        $child->thoughts()->attach($thought->id, ['sort_order' => 1]);
        $thought->load('projects:id');

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $root->id, $root)
        );
    }

    #[Test]
    public function client_root_matches_thought_tagged_client_slug_only(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $thought = Thought::factory()->for($user)->create([
            'metadata' => ['tags' => ['client:dezeen', 'working-memory']],
        ]);

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $root->id, $root)
        );
    }

    #[Test]
    public function client_root_matches_thought_with_client_metadata_project(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $thought = Thought::factory()->for($user)->create([
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $root->id, $root)
        );
    }

    #[Test]
    public function client_root_rejects_sibling_subproject_metadata(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        $thought = Thought::factory()->for($user)->create([
            'source_metadata' => ['project' => 'dezeen/foo'],
        ]);

        $this->assertFalse(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $root->id, $root)
        );
    }

    #[Test]
    public function child_scope_matches_thought_linked_to_child(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        $thought = Thought::factory()->for($user)->create();
        $child->thoughts()->attach($thought->id, ['sort_order' => 1]);
        $thought->load('projects:id');

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $child->id, $child)
        );
    }

    #[Test]
    public function child_scope_matches_thought_with_composite_metadata_project(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        $thought = Thought::factory()->for($user)->create([
            'source_metadata' => ['project' => 'dezeen/foo'],
        ]);

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($thought, (string) $child->id, $child)
        );
    }

    #[Test]
    public function legacy_slug_scope_matches_exact_metadata_or_linked_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $metadataThought = Thought::factory()->for($user)->create([
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        $linkedThought = Thought::factory()->for($user)->create();
        $project->thoughts()->attach($linkedThought->id, ['sort_order' => 1]);
        $linkedThought->load('projects:id');

        $unrelated = Thought::factory()->for($user)->create([
            'source_metadata' => ['project' => 'dezeen/foo'],
        ]);

        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($metadataThought, 'dezeen', null)
        );
        $this->assertTrue(
            $this->matcher->thoughtMatchesProjectScope($linkedThought, (string) $project->id, null)
        );
        $this->assertFalse(
            $this->matcher->thoughtMatchesProjectScope($unrelated, 'dezeen', null)
        );
    }
}

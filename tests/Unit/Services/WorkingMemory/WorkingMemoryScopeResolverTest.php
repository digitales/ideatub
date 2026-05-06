<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_always_includes_global_scope(): void
    {
        $thought = Thought::factory()->create();

        $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);

        $this->assertSame([
            ['scope_type' => 'global', 'scope_key' => 'global'],
        ], $scopes);
    }

    #[Test]
    public function it_includes_insights_scope_for_research_classified_thoughts(): void
    {
        $thought = Thought::factory()->create([
            'metadata' => ['type' => 'research', 'tags' => ['q1']],
        ]);

        $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);

        $this->assertEquals([
            ['scope_type' => 'global', 'scope_key' => 'global'],
            ['scope_type' => 'insights', 'scope_key' => 'global'],
        ], $scopes);
    }

    #[Test]
    public function it_includes_normalized_project_scope_from_source_metadata(): void
    {
        $thought = Thought::factory()->create([
            'source_metadata' => ['project' => '  My-App  '],
        ]);

        $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);

        $this->assertSame([
            ['scope_type' => 'global', 'scope_key' => 'global'],
            ['scope_type' => 'project', 'scope_key' => 'my-app'],
        ], $scopes);
    }

    #[Test]
    public function it_includes_linked_project_scopes_and_deduplicates_deterministically(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'source_metadata' => ['project' => '  MY-APP  '],
        ]);
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $thought->projects()->attach($projectA->id, ['sort_order' => 2]);
        $thought->projects()->attach($projectB->id, ['sort_order' => 3]);

        $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);

        $this->assertSame([
            ['scope_type' => 'global', 'scope_key' => 'global'],
            ['scope_type' => 'project', 'scope_key' => 'my-app'],
            ['scope_type' => 'project', 'scope_key' => $projectA->id],
            ['scope_type' => 'project', 'scope_key' => $projectB->id],
        ], $scopes);
    }

    #[Test]
    public function it_deduplicates_metadata_and_linked_project_scopes_using_stable_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create([
            'source_metadata' => ['project' => '  '.strtoupper($project->id).'  '],
        ]);

        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        $scopes = app(WorkingMemoryScopeResolver::class)->forThought($thought);

        $this->assertSame([
            ['scope_type' => 'global', 'scope_key' => 'global'],
            ['scope_type' => 'project', 'scope_key' => $project->id],
        ], $scopes);
    }
}

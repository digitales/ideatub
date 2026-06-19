<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\ThoughtScopeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThoughtScopeQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_limits_global_scope_queries_without_loading_the_full_corpus(): void
    {
        $user = User::factory()->create();

        Thought::factory()->count(30)->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-01 10:00:00', 'UTC'),
        ]);

        $recent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent global signal.',
            'created_at' => Carbon::parse('2026-05-10 10:00:00', 'UTC'),
        ]);

        $results = app(ThoughtScopeQuery::class)->forScope($user->id, 'global', 'global', null, 5);

        $this->assertCount(5, $results);
        $this->assertTrue($results->contains(fn (Thought $thought): bool => (string) $thought->id === (string) $recent->id));
    }

    #[Test]
    public function it_filters_tag_scope_at_the_database(): void
    {
        $user = User::factory()->create();

        $matching = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['tags' => ['ai']],
            'content' => 'Tagged thought.',
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['tags' => ['roadmap']],
            'content' => 'Other tag.',
        ]);

        $results = app(ThoughtScopeQuery::class)->forScope($user->id, 'tag', 'ai', null, 10);

        $this->assertCount(1, $results);
        $this->assertSame((string) $matching->id, (string) $results->first()->id);
    }

    #[Test]
    public function it_filters_project_scope_by_pivot_and_metadata_slug(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $linked = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Linked via pivot.']);
        $linked->projects()->attach($project->id);

        $slugged = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Linked via metadata slug.',
            'source_metadata' => ['project' => 'my-app'],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Unrelated thought.',
        ]);

        $byUuid = app(ThoughtScopeQuery::class)->forScope($user->id, 'project', (string) $project->id, null, 10);
        $this->assertCount(1, $byUuid);
        $this->assertSame((string) $linked->id, (string) $byUuid->first()->id);

        $bySlug = app(ThoughtScopeQuery::class)->forScope($user->id, 'project', 'my-app', null, 10);
        $this->assertCount(1, $bySlug);
        $this->assertSame((string) $slugged->id, (string) $bySlug->first()->id);
    }

    #[Test]
    public function it_applies_since_cutoff_for_incremental_windows(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-01 10:00:00', 'UTC'),
        ]);

        $fresh = Thought::factory()->create([
            'user_id' => $user->id,
            'created_at' => Carbon::parse('2026-05-10 10:00:00', 'UTC'),
        ]);

        $since = Carbon::parse('2026-05-09 00:00:00', 'UTC');
        $results = app(ThoughtScopeQuery::class)->forScope($user->id, 'global', 'global', $since, 10);

        $this->assertCount(1, $results);
        $this->assertSame((string) $fresh->id, (string) $results->first()->id);
    }
}

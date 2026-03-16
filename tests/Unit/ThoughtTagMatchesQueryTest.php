<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtTagMatchesQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_tag_matches_query_exact_match(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec', 'work']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('decision:project-spec')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_tag_contains_query(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('project-spec')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_no_match_returns_empty(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['work']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('decision:other')
            ->get();

        $this->assertCount(0, $found);
    }

    public function test_scope_tag_matches_query_normalizes_query_to_lowercase(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('DECISION:PROJECT-SPEC')
            ->get();

        $this->assertCount(1, $found);
    }

    public function test_scope_tag_matches_query_empty_metadata_tags_returns_empty(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Some content',
            'metadata' => [],
        ]);

        $found = Thought::query()
            ->where('user_id', $user->id)
            ->tagMatchesQuery('work')
            ->get();

        $this->assertCount(0, $found);
    }
}

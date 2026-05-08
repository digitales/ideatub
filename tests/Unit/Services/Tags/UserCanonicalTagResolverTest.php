<?php

namespace Tests\Unit\Services\Tags;

use App\Models\Thought;
use App\Models\User;
use App\Services\Tags\UserCanonicalTagResolver;
use App\Support\TagSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserCanonicalTagResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_many_returns_empty_for_empty_slugs(): void
    {
        $user = User::factory()->create();
        $resolver = app(UserCanonicalTagResolver::class);

        $this->assertSame([], $resolver->resolveMany((int) $user->id, []));
    }

    public function test_first_tag_string_wins_when_multiple_normalize_to_same_slug(): void
    {
        $user = User::factory()->create();

        Thought::factory()->for($user)->create([
            'metadata' => ['tags' => ['Keep Me', 'keep me']],
        ]);

        $slug = TagSlug::from('Keep Me');

        $resolver = app(UserCanonicalTagResolver::class);
        $resolved = $resolver->resolveMany((int) $user->id, [$slug]);

        $this->assertSame('Keep Me', $resolved[$slug]);
    }

    public function test_resolve_single_matches_resolve_many(): void
    {
        $user = User::factory()->create();

        Thought::factory()->for($user)->create([
            'metadata' => ['tags' => ['Stream Label']],
        ]);

        $slug = TagSlug::from('Stream Label');
        $resolver = app(UserCanonicalTagResolver::class);

        $this->assertSame(
            $resolver->resolve((int) $user->id, $slug),
            $resolver->resolveMany((int) $user->id, [$slug])[$slug],
        );
    }

    public function test_resolve_many_returns_null_when_no_thought_contains_slug(): void
    {
        $user = User::factory()->create();

        Thought::factory()->for($user)->create([
            'metadata' => ['tags' => ['other']],
        ]);

        $resolver = app(UserCanonicalTagResolver::class);
        $resolved = $resolver->resolveMany((int) $user->id, ['missing_slug']);

        $this->assertNull($resolved['missing_slug']);
    }

    public function test_sqlite_tag_iteration_uses_single_select(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific query-count assertion.');
        }

        $user = User::factory()->create();

        foreach (range(1, 8) as $i) {
            Thought::factory()->for($user)->create([
                'metadata' => ['tags' => ["label-$i"]],
            ]);
        }

        $resolver = app(UserCanonicalTagResolver::class);

        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if ($query->sql !== '' && preg_match('/^\s*select/i', $query->sql)
                && preg_match('/\bthoughts\b/i', $query->sql)) {
                $selects++;
            }
        });

        $resolver->resolveMany((int) $user->id, ['label_1', 'label_2']);

        $this->assertSame(1, $selects);
    }
}

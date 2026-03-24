<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use App\Support\IdeaCompletedAtSql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThoughtTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_ideas_returns_only_thoughts_with_type_idea(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create(['user_id' => $user->id, 'metadata' => null]);
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'note']]);
        $idea = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea']]);

        $ideas = Thought::query()->where('user_id', $user->id)->ideas()->get();

        $this->assertCount(1, $ideas);
        $this->assertTrue($ideas->first()->is($idea));
    }

    public function test_logged_date_returns_metadata_logged_date_or_created_at_date(): void
    {
        $user = User::factory()->create();

        $withLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'logged_date' => '2025-03-10'],
        ]);
        $withoutLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertSame('2025-03-10', $withLogged->getLoggedDate());
        $this->assertSame($withoutLogged->created_at->toDateString(), $withoutLogged->getLoggedDate());
    }

    public function test_is_idea_completed_returns_true_for_completed_flag_or_meaningful_completed_at(): void
    {
        $user = User::factory()->create();

        $completed = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
        ]);
        $timestampOnly = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'completed_at' => '2026-03-10T10:00:00+00:00'],
        ]);
        $malformedTimestampOnly = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'completed_at' => 'not-a-date'],
        ]);
        $incomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false],
        ]);
        $noFlag = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertTrue($completed->isIdeaCompleted());
        $this->assertTrue($timestampOnly->isIdeaCompleted());
        $this->assertTrue($malformedTimestampOnly->isIdeaCompleted());
        $this->assertFalse($incomplete->isIdeaCompleted());
        $this->assertFalse($noFlag->isIdeaCompleted());
    }

    public function test_scope_incomplete_ideas_excludes_completed_but_keeps_missing_completed_flag(): void
    {
        $user = User::factory()->create();

        $completed = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
        ]);
        $missingFlag = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);
        $explicitIncomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false],
        ]);

        $incomplete = Thought::query()
            ->where('user_id', $user->id)
            ->incompleteIdeas()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $incomplete);
        $this->assertTrue($incomplete->contains('id', $missingFlag->id));
        $this->assertTrue($incomplete->contains('id', $explicitIncomplete->id));
        $this->assertFalse($incomplete->contains('id', $completed->id));
    }

    public function test_scope_incomplete_ideas_excludes_rows_with_completed_at_even_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();

        $eligible = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-01-01',
            ],
        ]);
        $timestamped = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'completed_at' => now()->toIso8601String(),
                'logged_date' => '2025-01-02',
            ],
        ]);

        $incomplete = Thought::query()
            ->where('user_id', $user->id)
            ->incompleteIdeas()
            ->get();

        $this->assertTrue($incomplete->contains('id', $eligible->id));
        $this->assertFalse($incomplete->contains('id', $timestamped->id));
    }

    public function test_scope_completed_ideas_returns_only_completed_ideas(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'note', 'completed' => true],
        ]);

        $completed = Thought::query()
            ->where('user_id', $user->id)
            ->completedIdeas()
            ->get();

        $this->assertCount(1, $completed);
        $this->assertTrue($completed->first()->isIdeaCompleted());
    }

    public function test_scope_completed_ideas_includes_ideas_with_nonempty_completed_at_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();

        $timestampOnly = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'completed_at' => '2026-03-10T10:00:00+00:00',
                'logged_date' => '2025-01-02',
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);

        $completed = Thought::query()
            ->where('user_id', $user->id)
            ->completedIdeas()
            ->get();

        $this->assertCount(1, $completed);
        $this->assertTrue($completed->first()->is($timestampOnly));
        $this->assertTrue($completed->first()->isIdeaCompleted());
    }

    public function test_scope_completed_ideas_includes_malformed_nonempty_completed_at_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();

        $malformed = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'completed_at' => 'not-a-date',
                'logged_date' => '2025-01-01',
            ],
        ]);

        $completed = Thought::query()
            ->where('user_id', $user->id)
            ->completedIdeas()
            ->get();

        $this->assertCount(1, $completed);
        $this->assertTrue($completed->first()->is($malformed));
        $this->assertTrue($completed->first()->isIdeaCompleted());
        $this->assertNull($completed->first()->getIdeaCompletedAt());
    }

    public function test_get_idea_completed_at_returns_parsed_timestamp_or_null(): void
    {
        $user = User::factory()->create();

        $dateOnly = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed_at' => '2026-01-14'],
        ]);
        $withAt = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed_at' => '2026-01-15T12:00:00+00:00'],
        ]);
        $nonCanonical = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed_at' => '2026-01-15 12:00:00'],
        ]);
        $invalid = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed_at' => 'not-a-date'],
        ]);
        $missing = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $this->assertInstanceOf(Carbon::class, $dateOnly->getIdeaCompletedAt());
        $this->assertTrue($dateOnly->getIdeaCompletedAt()->equalTo(Carbon::createFromFormat('Y-m-d H:i:sP', '2026-01-14 00:00:00+00:00')));
        $this->assertInstanceOf(Carbon::class, $withAt->getIdeaCompletedAt());
        $this->assertTrue($withAt->getIdeaCompletedAt()->equalTo(Carbon::parse('2026-01-15T12:00:00+00:00')));
        $this->assertNull($nonCanonical->getIdeaCompletedAt());
        $this->assertNull($invalid->getIdeaCompletedAt());
        $this->assertNull($missing->getIdeaCompletedAt());
    }

    public function test_idea_completed_at_sql_rejects_unsupported_database_drivers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [mysql] for completed idea ordering.');

        IdeaCompletedAtSql::parsedCompletedAtExpression('mysql');
    }

    public function test_idea_completed_at_sql_sqlite_expression_rejects_datetime_without_timezone(): void
    {
        $expression = IdeaCompletedAtSql::parsedCompletedAtExpression('sqlite');

        $this->assertStringContainsString('[T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z', $expression);
        $this->assertStringContainsString('[T ][0-9][0-9]:[0-9][0-9]:[0-9][0-9][+-][0-9][0-9]:[0-9][0-9]', $expression);
        $this->assertStringNotContainsString(
            "THEN datetime(json_extract(metadata, '$.completed_at')) ELSE NULL END",
            $expression
        );
    }

    public function test_get_idea_completed_at_returns_null_for_non_string_values(): void
    {
        $user = User::factory()->create();

        $nonString = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed_at' => ['unexpected']],
        ]);

        $this->assertNull($nonString->getIdeaCompletedAt());
    }

    public function test_completed_idea_ordering_timestamped_before_legacy_newest_completed_at_first(): void
    {
        $user = User::factory()->create();

        $legacy = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => Carbon::parse('2026-03-01 10:00:00'),
        ]);
        $older = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'completed_at' => '2026-02-01T10:00:00+00:00',
            ],
            'updated_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);
        $newer = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'updated_at' => Carbon::parse('2026-03-05 10:00:00'),
        ]);

        $ordered = IdeaCompletedAtSql::applyCompletedIdeaOrdering(
            Thought::query()->where('user_id', $user->id)->completedIdeas()
        )->get();

        $this->assertSame(
            [$newer->id, $older->id, $legacy->id],
            $ordered->pluck('id')->all()
        );
    }

    public function test_completed_idea_ordering_legacy_tie_breaks_by_updated_at_then_id_desc(): void
    {
        $user = User::factory()->create();
        $base = Carbon::parse('2026-03-01 12:00:00');

        $olderLegacy = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => $base->copy()->subDay(),
        ]);
        $newerLegacy = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => $base,
        ]);

        $ordered = IdeaCompletedAtSql::applyCompletedIdeaOrdering(
            Thought::query()->where('user_id', $user->id)->completedIdeas()
        )->get();

        $this->assertSame(
            [$newerLegacy->id, $olderLegacy->id],
            $ordered->pluck('id')->all()
        );
    }

    public function test_completed_idea_ordering_treats_malformed_timestamp_like_values_as_legacy_rows(): void
    {
        $user = User::factory()->create();

        $validTimestamped = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'completed_at' => '2026-03-10T10:00:00+00:00',
            ],
            'updated_at' => Carbon::parse('2026-03-01 09:00:00'),
        ]);
        $legacyWithoutTimestamp = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => Carbon::parse('2026-03-12 09:00:00'),
        ]);
        $malformedTimestampLike = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => true,
                'completed_at' => '2026-13-40T10:00:00+00:00',
            ],
            'updated_at' => Carbon::parse('2026-03-11 09:00:00'),
        ]);

        $ordered = IdeaCompletedAtSql::applyCompletedIdeaOrdering(
            Thought::query()->where('user_id', $user->id)->completedIdeas()
        )->get();

        $this->assertSame(
            [$validTimestamped->id, $legacyWithoutTimestamp->id, $malformedTimestampLike->id],
            $ordered->pluck('id')->all()
        );
    }

    public function test_completed_idea_ordering_legacy_rows_fall_back_to_id_desc_when_updated_at_matches(): void
    {
        $user = User::factory()->create();
        $updatedAt = Carbon::parse('2026-03-15 12:00:00');

        $lowerId = Thought::factory()->create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => $updatedAt,
        ]);
        $higherId = Thought::factory()->create([
            'id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
            'updated_at' => $updatedAt,
        ]);

        $ordered = IdeaCompletedAtSql::applyCompletedIdeaOrdering(
            Thought::query()->where('user_id', $user->id)->completedIdeas()
        )->get();

        $this->assertSame(
            [$higherId->id, $lowerId->id],
            $ordered->pluck('id')->all()
        );
    }

    public function test_content_is_normalized_on_save_html_entities_decoded(): void
    {
        $user = User::factory()->create();

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Daphne&#039;s breathing was 30 per minute.',
        ]);

        $this->assertSame("Daphne's breathing was 30 per minute.", $thought->content);
        $this->assertSame("Daphne's breathing was 30 per minute.", $thought->getDecodedContent());
    }

    public function test_decode_content_entities_handles_double_encoding(): void
    {
        $this->assertSame("Daphne's", Thought::decodeContentEntities('Daphne&amp;#039;s'));
        $this->assertSame('foo "bar"', Thought::decodeContentEntities('foo &quot;bar&quot;'));
    }

    public function test_decode_content_entities_handles_numeric_entity_without_semicolon(): void
    {
        // PHP's html_entity_decode does not decode &#039s (no semicolon); we normalize so it decodes.
        $this->assertSame("Daphne's breathing", Thought::decodeContentEntities('Daphne&#039s breathing'));
        $this->assertSame("Daphne's", Thought::decodeContentEntities('Daphne&#039;s'));
    }
}

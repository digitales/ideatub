<?php

namespace Tests\Unit\Services\Inbox;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Generators\NeglectedIdeaInboxGenerator;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NeglectedIdeaInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function returns_empty_array_when_no_ideas_qualify(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(5)->toDateString()],
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);

        $this->assertSame([], $generator->generate($user));
    }

    #[Test]
    public function returns_a_candidate_with_the_expected_payload_shape(): void
    {
        $user = User::factory()->create();

        $oldIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(45)->toDateString()],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(5)->toDateString()],
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('neglected_idea', $candidates[0]['generator_type']);
        $this->assertSame('Neglected idea', $candidates[0]['title']);
        $this->assertSame('neglected_idea:'.$oldIdea->id, $candidates[0]['dedupe_key']);
        $this->assertInstanceOf(CarbonInterface::class, $candidates[0]['generated_at']);
        $this->assertSame([
            'idea_id' => $oldIdea->id,
            'logged_date' => $oldIdea->metadata['logged_date'],
        ], $candidates[0]['source_data']);
        $this->assertStringContainsString('Neglected idea', $candidates[0]['body']);
    }

    #[Test]
    public function excludes_completed_ideas_and_ideas_belonging_to_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Completed neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => now()->subDays(60)->toDateString()],
        ]);

        Thought::factory()->create([
            'user_id' => $otherUser->id,
            'content' => 'Another user neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(60)->toDateString()],
        ]);

        $qualifyingIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'My neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(60)->toDateString()],
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('neglected_idea:'.$qualifyingIdea->id, $candidates[0]['dedupe_key']);
        $this->assertStringContainsString('My neglected idea', $candidates[0]['body']);
    }

    #[Test]
    public function falls_back_to_created_at_when_logged_date_is_missing(): void
    {
        $user = User::factory()->create();

        $oldIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Created at neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false],
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Fresh created at idea',
            'metadata' => ['type' => 'idea', 'completed' => false],
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('neglected_idea:'.$oldIdea->id, $candidates[0]['dedupe_key']);
        $this->assertSame([
            'idea_id' => $oldIdea->id,
            'logged_date' => null,
        ], $candidates[0]['source_data']);
    }

    #[Test]
    public function falls_back_to_created_at_when_logged_date_is_malformed(): void
    {
        $user = User::factory()->create();

        $oldIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Malformed logged date idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'not-a-date'],
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent malformed logged date idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'still-not-a-date'],
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('neglected_idea:'.$oldIdea->id, $candidates[0]['dedupe_key']);
        $this->assertStringContainsString('Malformed logged date idea', $candidates[0]['body']);
    }

    #[Test]
    public function limits_results_to_the_two_oldest_qualifying_ideas(): void
    {
        $user = User::factory()->create();

        $oldestIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Oldest neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(90)->toDateString()],
        ]);

        $secondOldestIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Second oldest neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(75)->toDateString()],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Third neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(60)->toDateString()],
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(2, $candidates);
        $this->assertSame('neglected_idea:'.$oldestIdea->id, $candidates[0]['dedupe_key']);
        $this->assertSame('neglected_idea:'.$secondOldestIdea->id, $candidates[1]['dedupe_key']);
    }
}

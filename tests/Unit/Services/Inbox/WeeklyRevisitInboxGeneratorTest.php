<?php

namespace Tests\Unit\Services\Inbox;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\Inbox\Contracts\InboxGenerator;
use App\Services\Inbox\Generators\WeeklyRevisitInboxGenerator;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeeklyRevisitInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returns_empty_array_when_no_revisit_ideas_exist(): void
    {
        $user = User::factory()->create();

        $generator = app(WeeklyRevisitInboxGenerator::class);

        $this->assertInstanceOf(InboxGenerator::class, $generator);
        $this->assertSame([], $generator->generate($user));
    }

    #[Test]
    public function returns_a_single_candidate_with_the_full_expected_shape(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 3);

        $oldIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);

        $anotherIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Another old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-02'],
        ]);

        $generator = app(WeeklyRevisitInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('weekly_revisit', $candidates[0]['generator_type']);
        $this->assertSame('Weekly revisit', $candidates[0]['title']);
        $this->assertSame('weekly-revisit', $candidates[0]['dedupe_key']);
        $this->assertInstanceOf(CarbonInterface::class, $candidates[0]['generated_at']);
        $this->assertSame([
            'idea_ids' => [$oldIdea->id, $anotherIdea->id],
        ], $candidates[0]['source_data']);
        $this->assertStringContainsString('Old idea', $candidates[0]['body']);
        $this->assertStringContainsString('Another old idea', $candidates[0]['body']);
    }

    #[Test]
    public function normalizes_multiline_and_extra_whitespace_in_bullets(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 3);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => "  Messy idea title \n\n with awkward\tspacing  ",
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);

        $generator = app(WeeklyRevisitInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertSame(
            "Review these older ideas:\n- Messy idea title with awkward spacing",
            $candidates[0]['body']
        );
    }
}

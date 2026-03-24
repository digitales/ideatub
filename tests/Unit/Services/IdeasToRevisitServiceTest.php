<?php

namespace Tests\Unit\Services;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\IdeasToRevisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdeasToRevisitServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returns_incomplete_ideas_ordered_by_age_oldest_first_limited_by_preference(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 3);

        $oldest = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);
        $middle = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-02-01'],
        ]);
        $newest = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
        ]);
        $fourth = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-04-01'],
        ]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $this->assertCount(3, $result);
        $this->assertSame($oldest->id, $result[0]->id);
        $this->assertSame($middle->id, $result[1]->id);
        $this->assertSame($newest->id, $result[2]->id);
    }

    #[Test]
    public function excludes_ideas_newer_than_min_age_days_when_preference_set(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);
        UserPreference::set($user, 'ideas_to_revisit_min_age_days', 7);

        $oldEnough = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(10)->toDateString()],
        ]);
        $tooNew = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(3)->toDateString()],
        ]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $this->assertCount(1, $result);
        $this->assertSame($oldEnough->id, $result[0]->id);
    }

    #[Test]
    public function returns_empty_list_when_user_has_no_incomplete_ideas(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-01-01'],
        ]);
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'note']]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $this->assertSame([], $result);
    }

    #[Test]
    public function excludes_ideas_with_completed_at_even_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);

        $eligible = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-01-02',
                'completed_at' => now()->toIso8601String(),
            ],
        ]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $this->assertCount(1, $result);
        $this->assertSame($eligible->id, $result[0]->id);
    }

    #[Test]
    public function excludes_completed_ideas_only_incomplete_type_idea_returned(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);

        $incomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-01-02'],
        ]);
        $noFlag = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'logged_date' => '2025-01-03'],
        ]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $ids = array_map(fn ($t) => $t->id, $result);
        $this->assertContains($incomplete->id, $ids);
        $this->assertContains($noFlag->id, $ids);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function returns_only_ideas_excludes_research_and_other_thought_types(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 15);

        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'idea_id' => $idea->id],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'note'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['completed' => false, 'logged_date' => '2025-01-01'],
        ]);

        $service = new IdeasToRevisitService;
        $result = $service->forUser($user);

        $this->assertCount(1, $result);
        $this->assertSame($idea->id, $result[0]->id);
    }

    #[Test]
    public function falls_back_to_created_at_when_logged_date_is_malformed_for_min_age_filter(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');
        try {
            $user = User::factory()->create();
            UserPreference::set($user, 'ideas_to_revisit_limit', 15);
            UserPreference::set($user, 'ideas_to_revisit_min_age_days', 7);

            $oldEnough = Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'not-a-date'],
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
            Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'also-bad'],
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
            ]);

            $service = new IdeasToRevisitService;
            $result = $service->forUser($user);

            $this->assertCount(1, $result);
            $this->assertSame($oldEnough->id, $result[0]->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function orders_by_created_at_when_logged_date_is_malformed(): void
    {
        Carbon::setTestNow('2026-03-20 12:00:00');
        try {
            $user = User::factory()->create();
            UserPreference::set($user, 'ideas_to_revisit_limit', 15);

            $older = Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'nope'],
                'created_at' => now()->subDays(20),
                'updated_at' => now()->subDays(20),
            ]);
            $newer = Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => 'nope'],
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ]);

            $service = new IdeasToRevisitService;
            $result = $service->forUser($user);

            $this->assertCount(2, $result);
            $this->assertSame($older->id, $result[0]->id);
            $this->assertSame($newer->id, $result[1]->id);
        } finally {
            Carbon::setTestNow();
        }
    }
}

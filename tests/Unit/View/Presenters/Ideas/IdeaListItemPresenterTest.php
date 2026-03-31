<?php

namespace Tests\Unit\View\Presenters\Ideas;

use App\Models\Thought;
use App\Models\User;
use App\View\Presenters\Ideas\IdeaListItemPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdeaListItemPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_logged_date_ymd_matching_get_logged_date(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-06-15'],
            'embedding' => null,
        ]);

        $row = IdeaListItemPresenter::from($thought, collect());

        $this->assertSame($thought->getLoggedDate(), $row->loggedDateYmd());
        $this->assertSame('2025-06-15', $row->loggedDateYmd());
    }

    #[Test]
    public function it_reflects_research_pending_from_metadata(): void
    {
        $user = User::factory()->create();
        $pending = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01', 'research_pending' => true],
            'embedding' => null,
        ]);
        $idle = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-02', 'research_pending' => false],
            'embedding' => null,
        ]);

        $this->assertTrue(IdeaListItemPresenter::from($pending, collect())->isResearchPending());
        $this->assertFalse(IdeaListItemPresenter::from($idle, collect())->isResearchPending());
    }

    #[Test]
    public function it_exposes_the_grouped_research_list_for_branching_in_the_view(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $r1 = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Research A',
            'metadata' => ['type' => 'research', 'idea_id' => $idea->id],
            'embedding' => null,
        ]);
        $research = collect([$r1]);

        $row = IdeaListItemPresenter::from($idea, $research);

        $this->assertCount(1, $row->researchList());
        $this->assertTrue($row->researchList()->isNotEmpty());
        $this->assertSame($r1->id, $row->researchList()->first()->id);
    }

    #[Test]
    public function it_exposes_thought_for_actions_and_embedded_partials(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);

        $row = IdeaListItemPresenter::from($thought, collect());

        $this->assertSame($thought->id, $row->thought()->id);
    }
}

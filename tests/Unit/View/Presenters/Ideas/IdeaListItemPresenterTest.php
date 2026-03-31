<?php

namespace Tests\Unit\View\Presenters\Ideas;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Ideas\IdeaListItemPresenter;
use App\View\Presenters\Ideas\IdeaResearchStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

        $row = IdeaListItemPresenter::from($thought, collect(), IdeaResearchStatusPresenter::from($thought, null, null));

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

        $this->assertTrue(IdeaListItemPresenter::from($pending, collect(), IdeaResearchStatusPresenter::from($pending, null, null))->isResearchPending());
        $this->assertFalse(IdeaListItemPresenter::from($idle, collect(), IdeaResearchStatusPresenter::from($idle, null, null))->isResearchPending());
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

        $row = IdeaListItemPresenter::from($idea, $research, IdeaResearchStatusPresenter::from($idea, null, null));

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

        $row = IdeaListItemPresenter::from($thought, collect(), IdeaResearchStatusPresenter::from($thought, null, null));

        $this->assertSame($thought->id, $row->thought()->id);
    }

    #[Test]
    public function it_returns_raw_display_content_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'UNIT_IDEAS_LIST_BODY_MARKER',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $this->actingAs($user);
        $row = IdeaListItemPresenter::from($thought, collect(), IdeaResearchStatusPresenter::from($thought, null, null));

        $this->assertSame('UNIT_IDEAS_LIST_BODY_MARKER', $row->displayContent());
        $this->assertTrue($row->contentEditable());
    }

    #[Test]
    public function it_obfuscates_display_content_and_research_previews_in_demo_mode_while_preserving_logged_date(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'UNIT_IDEAS_LIST_BODY_MARKER',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-06-20'],
            'embedding' => null,
        ]);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'UNIT_IDEAS_LIST_RESEARCH_MARKER_'.str_repeat('x', 200),
            'metadata' => ['type' => 'research', 'idea_id' => $idea->id],
            'embedding' => null,
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-ideas-list-presenter',
        ]);

        $this->actingAs($user);
        $row = IdeaListItemPresenter::from($idea, collect([$research]), IdeaResearchStatusPresenter::from($idea, null, null));

        try {
            $this->assertNotSame('UNIT_IDEAS_LIST_BODY_MARKER', $row->displayContent());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('UNIT_IDEAS_LIST_BODY_MARKER', 'thought_content'),
                $row->displayContent(),
            );
            $this->assertFalse($row->contentEditable());
            $this->assertSame('2025-06-20', $row->loggedDateYmd());

            $rows = $row->researchPreviewRows();
            $this->assertCount(1, $rows);
            $this->assertArrayHasKey('preview', $rows[0]);
            $this->assertArrayHasKey('research', $rows[0]);
            $this->assertStringNotContainsString('UNIT_IDEAS_LIST_RESEARCH_MARKER_', $rows[0]['preview']);
            $limitedRaw = Str::limit((string) $research->content, 120);
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate($limitedRaw, 'research_snippet'),
                $rows[0]['preview'],
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }

    #[Test]
    public function it_returns_limited_raw_research_preview_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $long = 'UNIT_RAW_RESEARCH_START'.str_repeat('y', 200);
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => $long,
            'metadata' => ['type' => 'research', 'idea_id' => $idea->id],
            'embedding' => null,
        ]);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $this->actingAs($user);

        $row = IdeaListItemPresenter::from($idea, collect([$research]), IdeaResearchStatusPresenter::from($idea, null, null));
        $preview = $row->researchPreviewRows()[0]['preview'];

        $this->assertSame(Str::limit($long, 120), $preview);
        $this->assertStringStartsWith('UNIT_RAW_RESEARCH_START', $preview);
    }
}

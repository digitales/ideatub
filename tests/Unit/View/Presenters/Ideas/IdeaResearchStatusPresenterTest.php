<?php

namespace Tests\Unit\View\Presenters\Ideas;

use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\View\Presenters\Ideas\IdeaResearchStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdeaResearchStatusPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_shows_in_progress_when_run_is_queued_and_includes_skill_name(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Deep dive',
            'is_default' => true,
            'allow_auto_run' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $run = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $skill->fresh()->latestVersion->id,
            'status' => 'queued',
        ]);
        $run->load('researchSkill');

        $p = IdeaResearchStatusPresenter::from($idea, $run, $run);

        $this->assertTrue($p->showsInProgress());
        $this->assertSame('Deep dive', $p->activeSkillName());
        $this->assertStringContainsString('Deep dive', $p->statusLine());
        $this->assertFalse($p->showsFailed());
    }

    #[Test]
    public function it_shows_in_progress_when_run_is_running(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Quick scan',
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $run = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $skill->fresh()->latestVersion->id,
            'status' => 'running',
        ]);
        $run->load('researchSkill');

        $p = IdeaResearchStatusPresenter::from($idea, $run, $run);

        $this->assertTrue($p->showsInProgress());
        $this->assertSame('Quick scan', $p->activeSkillName());
    }

    #[Test]
    public function it_falls_back_to_metadata_pending_when_no_active_run_record(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01', 'research_pending' => true],
            'embedding' => null,
        ]);

        $p = IdeaResearchStatusPresenter::from($idea, null, null);

        $this->assertTrue($p->showsInProgress());
        $this->assertNull($p->activeSkillName());
    }

    #[Test]
    public function it_shows_failed_when_latest_run_failed_and_nothing_active(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $skill = ResearchSkill::factory()->create(['user_id' => $user->id, 'name' => 'X']);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $failed = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $skill->fresh()->latestVersion->id,
            'status' => 'failed',
            'error_summary' => 'API rate limit',
        ]);
        $failed->load('researchSkill');

        $p = IdeaResearchStatusPresenter::from($idea, null, $failed);

        $this->assertFalse($p->showsInProgress());
        $this->assertTrue($p->showsFailed());
        $this->assertSame('API rate limit', $p->failedSummary());
        $this->assertSame('X', $p->failedSkillName());
    }

    #[Test]
    public function it_is_idle_when_latest_completed_and_not_pending(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
            'embedding' => null,
        ]);
        $skill = ResearchSkill::factory()->create(['user_id' => $user->id]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $done = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $skill->fresh()->latestVersion->id,
            'status' => 'completed',
        ]);

        $p = IdeaResearchStatusPresenter::from($idea, null, $done);

        $this->assertFalse($p->showsInProgress());
        $this->assertFalse($p->showsFailed());
    }
}

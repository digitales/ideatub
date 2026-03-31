<?php

namespace Tests\Feature;

use App\Jobs\RunResearchRun;
use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchRunWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_skills_versions_and_runs(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
            ],
        ]);

        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Market scan',
            'latest_version_number' => 1,
        ]);

        $version = ResearchSkillVersion::factory()->create([
            'research_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'quick_brief',
            'is_auto_run_eligible' => true,
        ]);

        $finalResearch = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $idea->id,
            'metadata' => [
                'type' => 'research',
            ],
        ]);

        $run = ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $version->id,
            'status' => 'completed',
            'workflow_type_snapshot' => 'quick_brief',
            'output_shape_snapshot' => ['sections' => ['summary', 'sources']],
            'current_stage' => 2,
            'usage_metadata' => ['tokens' => 1200],
            'final_research_thought_id' => $finalResearch->id,
            'error_summary' => null,
        ]);

        $this->assertDatabaseHas('research_skills', [
            'id' => $skill->id,
            'user_id' => $user->id,
            'name' => 'Market scan',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => false,
            'is_active' => true,
            'latest_version_number' => 1,
            'description' => '',
        ]);

        $this->assertDatabaseHas('research_skill_versions', [
            'id' => $version->id,
            'research_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'is_auto_run_eligible' => true,
            'intensity' => 'standard',
        ]);

        $this->assertDatabaseHas('research_runs', [
            'id' => $run->id,
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $version->id,
            'source' => 'web',
            'status' => 'completed',
            'current_stage' => 2,
            'total_stages' => 1,
            'final_research_thought_id' => $finalResearch->id,
            'workflow_type_snapshot' => 'quick_brief',
            'intensity_snapshot' => 'standard',
        ]);

        $run->refresh();
        $this->assertTrue($run->user->is($user));
        $this->assertTrue($run->ideaThought->is($idea));
        $this->assertTrue($run->researchSkill->is($skill));
        $this->assertTrue($run->researchSkillVersion->is($version));
        $this->assertTrue($run->skillVersion->is($version));
        $this->assertTrue($run->finalResearchThought->is($finalResearch));
        $this->assertNull($run->context_options_snapshot);

        $this->assertTrue($skill->user->is($user));
        $this->assertTrue($version->researchSkill->is($skill));
        $this->assertCount(1, $skill->versions);
        $this->assertTrue($skill->versions->first()->is($version));
        $this->assertCount(1, $skill->researchRuns);
        $this->assertTrue($skill->researchRuns->first()->is($run));

        $this->assertCount(1, $user->researchSkills);
        $this->assertTrue($user->researchSkills->first()->is($skill));
        $this->assertCount(1, $user->researchRuns);
        $this->assertTrue($user->researchRuns->first()->is($run));

        $this->assertCount(1, $idea->researchRuns);
        $this->assertTrue($idea->researchRuns->first()->is($run));
        $this->assertCount(1, $idea->ideaResearchRuns);
        $this->assertTrue($idea->ideaResearchRuns->first()->is($run));

        $this->assertNull($version->context_options);
        $this->assertNull($version->output_shape);
    }

    public function test_research_run_factory_creates_matching_skill_version_and_queued_status(): void
    {
        $run = ResearchRun::factory()->create();

        $run->refresh();

        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->researchSkillVersion);
        $this->assertSame($run->research_skill_id, $run->researchSkillVersion->research_skill_id);
    }

    public function test_research_run_cannot_reference_version_from_different_skill(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);
        $skill = ResearchSkill::factory()->create(['user_id' => $user->id]);
        $otherSkill = ResearchSkill::factory()->create(['user_id' => $user->id]);
        $otherVersion = ResearchSkillVersion::factory()->create([
            'research_skill_id' => $otherSkill->id,
        ]);

        $this->expectException(QueryException::class);

        ResearchRun::query()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $otherVersion->id,
            'source' => 'web',
            'status' => 'queued',
            'workflow_type_snapshot' => 'quick_brief',
            'context_options_snapshot' => null,
            'output_shape_snapshot' => null,
            'intensity_snapshot' => 'standard',
            'current_stage' => 0,
            'total_stages' => 1,
            'usage_metadata' => null,
            'final_research_thought_id' => null,
            'error_summary' => null,
        ]);
    }

    public function test_research_skill_version_factory_increments_versions_for_same_skill(): void
    {
        $skill = ResearchSkill::factory()->create();

        $firstVersion = ResearchSkillVersion::factory()->for($skill, 'researchSkill')->create();
        $secondVersion = ResearchSkillVersion::factory()->for($skill, 'researchSkill')->create();

        $this->assertSame(1, $firstVersion->version);
        $this->assertSame(2, $secondVersion->version);
    }

    public function test_idea_research_dispatches_run_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(ResearchSkillManager::class)->create($user, [
            'name' => 'Default',
            'is_default' => true,
        ]);

        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
            ],
        ]);

        $response = $this->actingAs($user)->post(route('ideas.research', $idea));

        $response->assertRedirect();

        $this->assertDatabaseHas('research_runs', [
            'idea_thought_id' => $idea->id,
            'user_id' => $user->id,
            'status' => 'queued',
            'source' => 'web',
        ]);

        $runId = (int) ResearchRun::query()->where('idea_thought_id', $idea->id)->value('id');

        Queue::assertPushed(RunResearchRun::class, function (RunResearchRun $job) use ($runId): bool {
            return $job->researchRunId === $runId;
        });
    }

    public function test_idea_research_reuses_existing_active_run_and_does_not_dispatch_second_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(ResearchSkillManager::class)->create($user, [
            'name' => 'Default',
            'is_default' => true,
        ]);

        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'idea',
            ],
        ]);

        $this->actingAs($user)->post(route('ideas.research', $idea));
        Queue::assertPushedTimes(RunResearchRun::class, 1);

        $firstRunId = (int) ResearchRun::query()->where('idea_thought_id', $idea->id)->value('id');

        $this->actingAs($user)->post(route('ideas.research', $idea));

        Queue::assertPushedTimes(RunResearchRun::class, 1);
        $this->assertSame(1, ResearchRun::query()->where('idea_thought_id', $idea->id)->count());
        $this->assertSame($firstRunId, (int) ResearchRun::query()->where('idea_thought_id', $idea->id)->value('id'));
    }
}

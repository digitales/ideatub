<?php

namespace Tests\Feature;

use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'workflow_type' => 'sequential',
            'instructions' => 'Find competitors and summarize.',
            'context_options' => ['include_links' => true],
            'output_shape' => ['sections' => ['summary', 'sources']],
            'intensity' => 'standard',
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
            'source' => 'manual',
            'status' => 'completed',
            'workflow_type_snapshot' => 'sequential',
            'context_options_snapshot' => ['include_links' => true],
            'output_shape_snapshot' => ['sections' => ['summary', 'sources']],
            'intensity_snapshot' => 'standard',
            'current_stage' => 2,
            'total_stages' => 2,
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
        ]);

        $this->assertDatabaseHas('research_skill_versions', [
            'id' => $version->id,
            'research_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'sequential',
            'is_auto_run_eligible' => true,
        ]);

        $this->assertDatabaseHas('research_runs', [
            'id' => $run->id,
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $version->id,
            'source' => 'manual',
            'status' => 'completed',
            'current_stage' => 2,
            'total_stages' => 2,
            'final_research_thought_id' => $finalResearch->id,
        ]);

        $run->refresh();
        $this->assertTrue($run->user->is($user));
        $this->assertTrue($run->ideaThought->is($idea));
        $this->assertTrue($run->researchSkill->is($skill));
        $this->assertTrue($run->researchSkillVersion->is($version));
        $this->assertTrue($run->finalResearchThought->is($finalResearch));

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

        $this->assertCount(1, $idea->ideaResearchRuns);
        $this->assertTrue($idea->ideaResearchRuns->first()->is($run));
    }
}

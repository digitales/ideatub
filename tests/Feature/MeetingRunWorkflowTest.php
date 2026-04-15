<?php

namespace Tests\Feature;

use App\Jobs\ProcessMeetingRun;
use App\Models\MeetingRun;
use App\Models\MeetingSkill;
use App\Models\MeetingSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\MeetingSkillManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MeetingRunWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_skills_versions_and_runs(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'meeting',
                'tags' => ['meeting:weekly-sync'],
            ],
        ]);

        $skill = MeetingSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Exec sync',
            'latest_version_number' => 1,
        ]);

        $version = MeetingSkillVersion::factory()->create([
            'meeting_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'meeting_brief',
            'is_auto_run_eligible' => true,
        ]);

        $finalAnalysis = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $meeting->id,
            'metadata' => [
                'type' => 'meeting_analysis',
            ],
        ]);

        $run = MeetingRun::factory()->create([
            'user_id' => $user->id,
            'meeting_thought_id' => $meeting->id,
            'meeting_skill_id' => $skill->id,
            'meeting_skill_version_id' => $version->id,
            'status' => 'completed',
            'workflow_type_snapshot' => 'meeting_brief',
            'output_shape_snapshot' => ['sections' => ['summary', 'decisions']],
            'core_categories_snapshot' => ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'],
            'custom_categories_snapshot' => ['budget'],
            'current_stage' => 1,
            'usage_metadata' => ['tokens' => 900],
            'final_meeting_thought_id' => $finalAnalysis->id,
            'error_summary' => null,
        ]);

        $this->assertDatabaseHas('meeting_skills', [
            'id' => $skill->id,
            'user_id' => $user->id,
            'name' => 'Exec sync',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => false,
            'is_active' => true,
            'latest_version_number' => 1,
            'description' => '',
        ]);

        $this->assertDatabaseHas('meeting_skill_versions', [
            'id' => $version->id,
            'meeting_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'meeting_brief',
            'instructions' => '',
            'is_auto_run_eligible' => true,
            'intensity' => 'standard',
        ]);

        $this->assertDatabaseHas('meeting_runs', [
            'id' => $run->id,
            'user_id' => $user->id,
            'meeting_thought_id' => $meeting->id,
            'meeting_skill_id' => $skill->id,
            'meeting_skill_version_id' => $version->id,
            'status' => 'completed',
            'final_meeting_thought_id' => $finalAnalysis->id,
            'workflow_type_snapshot' => 'meeting_brief',
        ]);

        $run->refresh();
        $this->assertTrue($run->user->is($user));
        $this->assertTrue($run->meetingThought->is($meeting));
        $this->assertTrue($run->meetingSkill->is($skill));
        $this->assertTrue($run->meetingSkillVersion->is($version));
        $this->assertTrue($run->skillVersion->is($version));
        $this->assertTrue($run->finalMeetingThought->is($finalAnalysis));
    }

    public function test_meeting_run_factory_creates_matching_skill_version_and_queued_status(): void
    {
        $run = MeetingRun::factory()->create();

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->meetingSkillVersion);
        $this->assertSame($run->meeting_skill_id, $run->meetingSkillVersion->meeting_skill_id);
    }

    public function test_meeting_run_cannot_reference_version_from_different_skill(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);
        $skill = MeetingSkill::factory()->create(['user_id' => $user->id]);
        $otherSkill = MeetingSkill::factory()->create(['user_id' => $user->id]);
        $otherVersion = MeetingSkillVersion::factory()->create([
            'meeting_skill_id' => $otherSkill->id,
        ]);

        $this->expectException(QueryException::class);

        MeetingRun::query()->create([
            'user_id' => $user->id,
            'meeting_thought_id' => $meeting->id,
            'meeting_skill_id' => $skill->id,
            'meeting_skill_version_id' => $otherVersion->id,
            'source' => 'web',
            'status' => 'queued',
            'workflow_type_snapshot' => 'meeting_brief',
            'context_options_snapshot' => null,
            'output_shape_snapshot' => null,
            'core_categories_snapshot' => ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'],
            'custom_categories_snapshot' => [],
            'intensity_snapshot' => 'standard',
            'current_stage' => 0,
            'total_stages' => 1,
            'usage_metadata' => null,
            'final_meeting_thought_id' => null,
            'error_summary' => null,
        ]);
    }

    public function test_queue_meeting_run_dispatches_process_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(MeetingSkillManager::class)->create($user, [
            'name' => 'Default meeting',
            'is_default' => true,
            'allow_auto_run' => true,
        ]);

        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        $run = app(MeetingService::class)->queueMeetingRunForThought($meeting, 'web');

        $this->assertDatabaseHas('meeting_runs', [
            'id' => $run->id,
            'meeting_thought_id' => $meeting->id,
            'user_id' => $user->id,
            'status' => 'queued',
            'source' => 'web',
        ]);

        Queue::assertPushed(ProcessMeetingRun::class, fn (ProcessMeetingRun $job): bool => $job->meetingRunId === $run->id);
    }
}

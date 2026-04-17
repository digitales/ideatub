<?php

namespace Tests\Feature;

use App\Models\MeetingSkill;
use App\Models\User;
use App\Services\Meetings\MeetingSkillManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingSkillSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function manager(): MeetingSkillManager
    {
        return app(MeetingSkillManager::class);
    }

    public function test_meeting_skills_create_page_requires_auth(): void
    {
        $response = $this->get(route('settings.skills.meeting.create'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_create_meeting_skill(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.skills.meeting.store'), [
            'name' => 'Standup brief',
            'description' => 'Weekly',
            'workflow_type' => MeetingSkillManager::WORKFLOW_MEETING_BRIEF,
            'instructions' => 'Summarize decisions.',
            'output_sections' => ['summary', 'actions'],
            'core_categories' => ['decisions', 'action_items'],
            'custom_categories_text' => "Parking lot\nNice to have",
            'intensity' => 'standard',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('settings.skills.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('meeting_skills', [
            'user_id' => $user->id,
            'name' => 'Standup brief',
            'is_default' => true,
        ]);

        $skill = MeetingSkill::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($skill);
        $latest = $this->manager()->latestVersion($skill);
        $this->assertNotNull($latest);
        $this->assertSame(['sections' => ['summary', 'actions']], $latest->output_shape);
        $this->assertSame(['Parking lot', 'Nice to have'], $latest->custom_categories);
    }

    public function test_user_can_edit_own_meeting_skill(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'M',
            'workflow_type' => MeetingSkillManager::WORKFLOW_MEETING_BRIEF,
            'instructions' => 'x',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($user)->get(route('settings.skills.meeting.edit', $skill));

        $response->assertOk();
        $response->assertSee('M');
    }

    public function test_user_cannot_edit_another_users_meeting_skill(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $skill = $this->manager()->create($owner, [
            'name' => 'Secret',
            'workflow_type' => MeetingSkillManager::WORKFLOW_MEETING_BRIEF,
            'instructions' => '',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($other)->get(route('settings.skills.meeting.edit', $skill));

        $response->assertForbidden();
    }

    public function test_legacy_get_research_skills_index_redirects_to_skills_hub(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.research-skills.index'));

        $response->assertRedirect(route('settings.skills.index'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\ResearchSkill;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchSkillSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function manager(): ResearchSkillManager
    {
        return app(ResearchSkillManager::class);
    }

    public function test_research_skills_index_requires_auth(): void
    {
        $response = $this->get(route('settings.research-skills.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_research_skills_index_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.research-skills.index'));

        $response->assertStatus(200);
        $response->assertSee('Research skills');
    }

    public function test_user_can_create_research_skill(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.research-skills.store'), [
            'name' => 'My brief',
            'description' => 'For quick checks',
            'workflow_type' => 'quick_brief',
            'instructions' => 'Summarize clearly.',
            'context_options' => ['idea' => true],
            'output_shape' => ['sections' => ['summary']],
            'intensity' => 'standard',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('settings.research-skills.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('research_skills', [
            'user_id' => $user->id,
            'name' => 'My brief',
            'is_default' => true,
        ]);

        $skill = ResearchSkill::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($skill);
        $this->assertSame(1, $skill->latest_version_number);
    }

    public function test_store_rejects_invalid_workflow_type(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.research-skills.store'), [
            'name' => 'X',
            'workflow_type' => 'deep_dive',
            'instructions' => '',
            'intensity' => 'standard',
        ]);

        $response->assertSessionHasErrors('workflow_type');
        $this->assertDatabaseCount('research_skills', 0);
    }

    public function test_user_can_edit_own_skill(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'Original',
            'workflow_type' => 'quick_brief',
            'instructions' => 'Old',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($user)->get(route('settings.research-skills.edit', $skill));

        $response->assertStatus(200);
        $response->assertSee('Original');
    }

    public function test_user_cannot_edit_another_users_skill(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $skill = $this->manager()->create($owner, [
            'name' => 'Secret',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($other)->get(route('settings.research-skills.edit', $skill));

        $response->assertForbidden();
    }

    public function test_user_can_update_own_skill(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'same',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($user)->put(route('settings.research-skills.update', $skill), [
            'name' => 'Updated name',
            'description' => '',
            'workflow_type' => 'quick_brief',
            'instructions' => 'New instructions',
            'context_options' => null,
            'output_shape' => null,
            'intensity' => 'concise',
            'is_manual_enabled' => true,
            'allow_auto_run' => true,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response->assertRedirect(route('settings.research-skills.index'));
        $response->assertSessionHas('success');

        $skill->refresh();
        $this->assertSame('Updated name', $skill->name);
        $latest = $this->manager()->latestVersion($skill);
        $this->assertNotNull($latest);
        $this->assertSame('New instructions', $latest->instructions);
        $this->assertSame('concise', $latest->intensity);
    }

    public function test_user_cannot_update_another_users_skill(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $skill = $this->manager()->create($owner, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'x',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($other)->put(route('settings.research-skills.update', $skill), [
            'name' => 'Hacked',
            'description' => '',
            'workflow_type' => 'quick_brief',
            'instructions' => 'y',
            'intensity' => 'standard',
            'is_manual_enabled' => true,
            'allow_auto_run' => false,
            'is_default' => false,
            'is_active' => true,
        ]);

        $response->assertForbidden();
        $skill->refresh();
        $this->assertSame('N', $skill->name);
    }

    public function test_user_can_set_default_skill_via_default_action(): void
    {
        $user = User::factory()->create();
        $a = $this->manager()->create($user, [
            'name' => 'A',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
            'is_default' => true,
        ]);
        $b = $this->manager()->create($user, [
            'name' => 'B',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)->post(route('settings.research-skills.default', $b));

        $response->assertRedirect(route('settings.research-skills.index'));
        $response->assertSessionHas('success');

        $a->refresh();
        $b->refresh();
        $this->assertFalse($a->is_default);
        $this->assertTrue($b->is_default);
    }

    public function test_user_cannot_set_default_on_another_users_skill(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $skill = $this->manager()->create($owner, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
        ]);

        $response = $this->actingAs($other)->post(route('settings.research-skills.default', $skill));

        $response->assertForbidden();
    }

    public function test_user_can_toggle_research_auto_run_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('settings.research-skills.preferences.update'), [
            'research_auto_run_enabled' => true,
        ]);

        $response->assertRedirect(route('settings.research-skills.index'));
        $response->assertSessionHas('success');
        $this->assertTrue(UserPreference::get($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, false));

        $this->actingAs($user)->put(route('settings.research-skills.preferences.update'), [
            'research_auto_run_enabled' => false,
        ]);

        $this->assertFalse(UserPreference::get($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, false));
    }
}

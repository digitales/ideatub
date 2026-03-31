<?php

namespace Tests\Unit\Services;

use App\Models\ResearchSkillVersion;
use App\Models\User;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResearchSkillManagerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): ResearchSkillManager
    {
        return new ResearchSkillManager;
    }

    #[Test]
    public function create_persists_skill_and_first_immutable_version(): void
    {
        $user = User::factory()->create();

        $skill = $this->manager()->create($user, [
            'name' => 'My skill',
            'description' => 'Desc',
            'workflow_type' => 'quick_brief',
            'instructions' => 'Do the thing',
            'context_options' => ['a' => 1],
            'output_shape' => ['sections' => ['summary']],
            'intensity' => 'standard',
            'is_manual_enabled' => true,
            'allow_auto_run' => true,
            'is_default' => false,
            'is_active' => true,
        ]);

        $skill->refresh();
        $this->assertSame(1, $skill->latest_version_number);
        $this->assertDatabaseCount('research_skill_versions', 1);

        $v = ResearchSkillVersion::query()->where('research_skill_id', $skill->id)->first();
        $this->assertSame(1, $v->version);
        $this->assertSame('quick_brief', $v->workflow_type);
        $this->assertSame('Do the thing', $v->instructions);
        $this->assertSame(['a' => 1], $v->context_options);
        $this->assertSame(['sections' => ['summary']], $v->output_shape);
        $this->assertSame('standard', $v->intensity);
        $this->assertTrue($v->is_auto_run_eligible);
    }

    #[Test]
    public function create_rejects_workflow_type_other_than_quick_brief(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->manager()->create($user, [
            'name' => 'X',
            'workflow_type' => 'deep_dive',
            'instructions' => '',
            'intensity' => 'standard',
        ]);
    }

    #[Test]
    public function create_sets_only_one_default_per_user(): void
    {
        $user = User::factory()->create();

        $first = $this->manager()->create($user, [
            'name' => 'First',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
            'is_default' => true,
        ]);

        $second = $this->manager()->create($user, [
            'name' => 'Second',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
            'is_default' => true,
        ]);

        $first->refresh();
        $second->refresh();

        $this->assertFalse($first->is_default);
        $this->assertTrue($second->is_default);
    }

    #[Test]
    public function update_without_behavioural_changes_does_not_append_version(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'same',
            'intensity' => 'standard',
        ]);

        $this->manager()->update($skill, [
            'name' => 'Renamed',
            'description' => 'New desc',
            'is_active' => false,
        ]);

        $skill->refresh();
        $this->assertSame(1, $skill->latest_version_number);
        $this->assertDatabaseCount('research_skill_versions', 1);
        $this->assertSame('Renamed', $skill->name);
        $this->assertSame('New desc', $skill->description);
        $this->assertFalse($skill->is_active);
    }

    #[Test]
    public function update_appends_version_when_instructions_change(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'v1',
            'intensity' => 'standard',
        ]);

        $this->manager()->update($skill, ['instructions' => 'v2']);

        $skill->refresh();
        $this->assertSame(2, $skill->latest_version_number);
        $this->assertDatabaseCount('research_skill_versions', 2);

        $latest = $this->manager()->latestVersion($skill);
        $this->assertNotNull($latest);
        $this->assertSame(2, $latest->version);
        $this->assertSame('v2', $latest->instructions);
    }

    #[Test]
    public function update_uses_latest_version_row_when_latest_version_number_is_stale(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'v1',
            'intensity' => 'standard',
        ]);

        ResearchSkillVersion::query()->create([
            'research_skill_id' => $skill->id,
            'version' => 2,
            'workflow_type' => 'quick_brief',
            'instructions' => 'v2',
            'context_options' => null,
            'output_shape' => null,
            'intensity' => 'standard',
            'is_auto_run_eligible' => false,
        ]);

        ResearchSkillVersion::query()->create([
            'research_skill_id' => $skill->id,
            'version' => 3,
            'workflow_type' => 'quick_brief',
            'instructions' => 'v3',
            'context_options' => null,
            'output_shape' => null,
            'intensity' => 'standard',
            'is_auto_run_eligible' => false,
        ]);

        $skill->update(['latest_version_number' => 1]);

        $this->manager()->update($skill, ['instructions' => 'v4']);

        $skill->refresh();
        $this->assertSame(4, $skill->latest_version_number);

        $latest = $this->manager()->latestVersion($skill);
        $this->assertNotNull($latest);
        $this->assertSame(4, $latest->version);
        $this->assertSame('v4', $latest->instructions);
    }

    #[Test]
    public function update_appends_version_when_auto_run_eligibility_changes(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'same',
            'intensity' => 'standard',
            'allow_auto_run' => false,
        ]);

        $v1 = $this->manager()->latestVersion($skill);
        $this->assertFalse($v1->is_auto_run_eligible);

        $this->manager()->update($skill, ['allow_auto_run' => true]);

        $skill->refresh();
        $this->assertSame(2, $skill->latest_version_number);
        $v2 = $this->manager()->latestVersion($skill);
        $this->assertTrue($v2->is_auto_run_eligible);
        $this->assertSame('same', $v2->instructions);
    }

    #[Test]
    public function update_clears_other_defaults_when_setting_is_default(): void
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

        $this->manager()->update($b, ['is_default' => true]);

        $a->refresh();
        $b->refresh();
        $this->assertFalse($a->is_default);
        $this->assertTrue($b->is_default);
    }

    #[Test]
    public function latest_version_returns_row_with_highest_version_number(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => 'one',
            'intensity' => 'standard',
        ]);
        $this->manager()->update($skill, ['instructions' => 'two']);

        $skill->refresh();
        $latest = $this->manager()->latestVersion($skill);

        $this->assertSame(2, $latest->version);
        $this->assertSame('two', $latest->instructions);
    }

    #[Test]
    public function update_rejects_non_quick_brief_workflow(): void
    {
        $user = User::factory()->create();
        $skill = $this->manager()->create($user, [
            'name' => 'N',
            'workflow_type' => 'quick_brief',
            'instructions' => '',
            'intensity' => 'standard',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->manager()->update($skill, ['workflow_type' => 'other']);
    }
}

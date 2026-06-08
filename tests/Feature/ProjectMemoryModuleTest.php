<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectMemoryModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function project_owner_sees_refresh_working_memory_in_main_column(): void
    {
        $this->withoutVite();
        config(['features.working_memory_ui' => true]);

        $user = User::factory()->create();
        assert($user instanceof User);
        $project = Project::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk();

        $html = (string) $response->getContent();
        $refreshAction = route('working-memory.refresh.project', $project);
        $response->assertSee('action="'.$refreshAction.'"', false);
        $response->assertSee('Working memory', false);
        $response->assertSee('Not built yet for this project.', false);
        $response->assertDontSee('Open project working memory', false);

        $matched = preg_match_all(
            '/<form[\s\S]*?data-working-memory-refresh[\s\S]*?>[\s\S]*?<\/form>/',
            $html,
            $matches
        );
        $this->assertSame(1, $matched, 'Exactly one refresh form should be present in the main column.');
        $formHtml = $matches[0][0];

        $this->assertStringContainsString('name="_token"', $formHtml);
        $this->assertStringContainsString('type="submit"', $formHtml);
        $this->assertStringContainsString('Refresh', $formHtml);
    }

    #[Test]
    public function project_show_renders_structured_sections_when_memory_is_built(): void
    {
        $this->withoutVite();
        config(['features.working_memory_ui' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => strtolower((string) $project->id),
            'last_refreshed_at' => now()->subHour(),
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'structured_sections_json' => [
                'Current Focus' => [[
                    'text' => 'Inline project memory focus line.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
                'Next Actions' => [[
                    'text' => 'Ship inline working memory.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [],
                ]],
            ],
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Current Focus', false);
        $response->assertSee('Inline project memory focus line.', false);
        $response->assertSee('Next Actions', false);
        $response->assertSee('Details &amp; metadata', false);
        $response->assertSee(route('projects.memory.versions', $project), false);
    }

    #[Test]
    public function project_show_does_not_auto_build_working_memory_when_missing(): void
    {
        $this->withoutVite();
        config(['features.working_memory_ui' => true]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $builder = Mockery::mock(WorkingMemoryBuilderService::class);
        $builder->shouldNotReceive('buildConsolidated');
        $this->app->instance(WorkingMemoryBuilderService::class, $builder);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Not built yet for this project.', false);

        $this->assertDatabaseCount('working_memory_versions', 0);
    }

    #[Test]
    public function project_show_hides_working_memory_when_feature_flag_disabled(): void
    {
        $this->withoutVite();
        config(['features.working_memory_ui' => false]);

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Working memory', false)
            ->assertDontSee('data-working-memory-refresh', false);
    }
}

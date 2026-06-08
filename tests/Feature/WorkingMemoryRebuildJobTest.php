<?php

namespace Tests\Feature;

use App\Jobs\WorkingMemoryRebuildJob;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryAutoRebuildService;
use App\Services\WorkingMemory\WorkingMemoryUpsertResult;
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryRebuildJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_job_skips_when_auto_update_is_false(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => false,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPromptCompletion');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $upsert = Mockery::mock(WorkingMemoryUpsertService::class);
        $upsert->shouldNotReceive('upsert');
        $this->app->instance(WorkingMemoryUpsertService::class, $upsert);

        $job = new WorkingMemoryRebuildJob((string) $project->id);
        $job->handle(app(WorkingMemoryAutoRebuildService::class));
    }

    #[Test]
    public function test_job_skips_when_debounce_window_is_active(): void
    {
        config([
            'working_memory.auto_rebuild_debounce_minutes' => 30,
            'working_memory.auto_rebuild_source_label_prefix' => 'auto-rebuild',
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
        ]);
        $recent = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'created_at' => now()->subMinutes(5),
            'build_diagnostics_json' => ['source_label' => 'auto-rebuild-2026-06-08-120000'],
        ]);
        $memory->update(['latest_version_id' => $recent->id]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPromptCompletion');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $upsert = Mockery::mock(WorkingMemoryUpsertService::class);
        $upsert->shouldNotReceive('upsert');
        $this->app->instance(WorkingMemoryUpsertService::class, $upsert);

        $job = new WorkingMemoryRebuildJob((string) $project->id);
        $job->handle(app(WorkingMemoryAutoRebuildService::class));
    }

    #[Test]
    public function test_job_calls_upsert_with_auto_rebuild_source_label_on_success(): void
    {
        config([
            'working_memory.auto_rebuild_debounce_minutes' => 30,
            'working_memory.auto_rebuild_source_label_prefix' => 'auto-rebuild',
            'working_memory.auto_rebuild_model' => 'claude-sonnet-4-20250514',
            'working_memory.auto_rebuild_max_tokens' => 2000,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);

        $assembler = Mockery::mock(WorkingMemoryAssembler::class);
        $assembler->shouldReceive('forScope')
            ->once()
            ->with($user->id, 'project', (string) $project->id)
            ->andReturn(['summary_markdown' => '']);
        $this->app->instance(WorkingMemoryAssembler::class, $assembler);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPromptCompletion')
            ->once()
            ->withArgs(function (string $userPrompt, ?string $model, $temperature, ?int $maxTokens, ?string $systemPrompt): bool {
                return str_contains($userPrompt, 'Write the updated working memory now.')
                    && $model === 'claude-sonnet-4-20250514'
                    && $maxTokens === 2000
                    && is_string($systemPrompt)
                    && str_contains($systemPrompt, 'Current Focus');
            })
            ->andReturn([
                'content' => "## Current Focus\nShipping auto-rebuild.",
                'finish_reason' => 'stop',
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 2000,
            ]);
        $this->app->instance(OpenRouterService::class, $openRouter);

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->make([
            'summary_markdown' => "## Current Focus\nShipping auto-rebuild.",
            'build_type' => 'external',
            'authoring_status' => 'external',
        ]);

        $upsert = Mockery::mock(WorkingMemoryUpsertService::class);
        $upsert->shouldReceive('upsert')
            ->once()
            ->withArgs(function (
                int $userId,
                string $scopeType,
                string $scopeKey,
                string $markdown,
                ?string $sourceLabel,
            ) use ($user, $project): bool {
                return $userId === $user->id
                    && $scopeType === 'project'
                    && $scopeKey === (string) $project->id
                    && str_contains($markdown, 'Shipping auto-rebuild.')
                    && is_string($sourceLabel)
                    && str_starts_with($sourceLabel, 'auto-rebuild-');
            })
            ->andReturn(new WorkingMemoryUpsertResult(
                version: $version,
                deduplicated: false,
                contentFingerprint: 'abc',
                dedupeFamily: 'project:'.(string) $project->id,
                supersededVersionId: null,
            ));
        $this->app->instance(WorkingMemoryUpsertService::class, $upsert);

        $job = new WorkingMemoryRebuildJob((string) $project->id);
        $job->handle(app(WorkingMemoryAutoRebuildService::class));
    }
}

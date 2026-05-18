<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class ImportWorkingMemoryCapturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_markdown_files_without_creating_thoughts(): void
    {
        $dir = $this->tempDir();
        File::put($dir.'/alpha.md', '# Alpha summary');
        $user = User::factory()->create();

        $exit = Artisan::call('working-memory:import-captures', [
            '--user' => (string) $user->id,
            '--project' => 'dezeen',
            '--path' => $dir,
            '--kind' => 'slack',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('alpha.md', Artisan::output());
        $this->assertSame(0, Thought::query()->count());
    }

    public function test_imports_slack_file_as_plan_thought_with_tags(): void
    {
        $dir = $this->tempDir();
        File::put($dir.'/client-dezeen-summary.md', '## Summary\n\nChannel activity today.');
        $user = User::factory()->create();

        $exit = Artisan::call('working-memory:import-captures', [
            '--user' => (string) $user->id,
            '--project' => 'dezeen',
            '--path' => $dir,
            '--kind' => 'slack',
            '--rate' => '120',
        ]);

        $this->assertSame(0, $exit);
        $thought = Thought::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($thought);
        $this->assertSame('plan', $thought->source);
        $tags = collect($thought->metadata['tags'] ?? [])->map(fn ($t) => (string) $t)->all();
        $this->assertContains('slack', $tags);
        $this->assertContains('client:dezeen', $tags);
    }

    public function test_consolidate_after_dispatches_job_when_project_id_set(): void
    {
        Queue::fake();
        $dir = $this->tempDir();
        File::put($dir.'/note.md', 'Automation scan output.');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        Artisan::call('working-memory:import-captures', [
            '--user' => (string) $user->id,
            '--project' => 'dezeen',
            '--project-id' => (string) $project->id,
            '--path' => $dir,
            '--kind' => 'automation',
            '--rate' => '120',
            '--consolidate-after' => true,
        ]);

        Queue::assertPushed(
            ConsolidateWorkingMemory::class,
            fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope(
                $job,
                $user->id,
                'project',
                (string) $project->id,
            ),
        );
    }

    private function matchesJobScope(ConsolidateWorkingMemory $job, int $userId, string $scopeType, string $scopeKey): bool
    {
        $reflection = new ReflectionClass($job);

        return $reflection->getProperty('userId')->getValue($job) === $userId
            && $reflection->getProperty('scopeType')->getValue($job) === $scopeType
            && $reflection->getProperty('scopeKey')->getValue($job) === $scopeKey;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/wm-import-'.uniqid('', true);
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}

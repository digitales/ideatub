<?php

namespace Tests\Feature;

use App\Jobs\ConsolidateWorkingMemory;
use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionException;
use Tests\TestCase;

class WorkingMemoryConsolidationCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_incremental_refresh_after_thought_creation(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Capture integration notes for incremental refresh behavior.',
        ]);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class);
    }

    #[Test]
    public function it_dispatches_incremental_refresh_after_meaningful_thought_updates(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Original content.',
        ]);

        Queue::fake();

        $thought->update([
            'content' => 'Updated content triggers working memory refresh.',
        ]);

        Queue::assertPushed(RefreshWorkingMemoryIncremental::class, 1);
    }

    #[Test]
    public function it_does_not_dispatch_incremental_refresh_for_non_meaningful_updates(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Original content.',
        ]);

        Queue::fake();

        $thought->update([
            'evernote_note_guid' => 'guid-123',
        ]);

        Queue::assertNotPushed(RefreshWorkingMemoryIncremental::class);
    }

    #[Test]
    public function it_queues_consolidation_jobs_for_all_discovered_scopes_for_a_user(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source_metadata' => ['project' => 'My-App'],
        ]);
        $thought->projects()->attach($project->id, ['sort_order' => 1]);

        $this->artisan('working-memory:consolidate', ['--user' => (string) $user->id])
            ->assertSuccessful();

        Queue::assertPushed(ConsolidateWorkingMemory::class, 3);
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'global', 'global'));
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'project', 'my-app'));
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'project', (string) $project->id));
    }

    #[Test]
    public function it_supports_scope_options_for_targeted_consolidation_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->artisan('working-memory:consolidate', [
            '--user' => (string) $user->id,
            '--scope_type' => 'project',
            '--scope_key' => 'my-app',
        ])->assertSuccessful();

        Queue::assertPushed(ConsolidateWorkingMemory::class, 1);
        Queue::assertPushed(ConsolidateWorkingMemory::class, fn (ConsolidateWorkingMemory $job): bool => $this->matchesJobScope($job, $user->id, 'project', 'my-app'));
    }

    #[Test]
    public function it_requires_scope_type_and_scope_key_to_be_supplied_together(): void
    {
        $this->artisan('working-memory:consolidate', ['--scope_type' => 'project'])
            ->assertFailed();
    }

    #[Test]
    public function it_rejects_non_numeric_user_option_values(): void
    {
        Queue::fake();

        $this->artisan('working-memory:consolidate', ['--user' => 'abc'])
            ->expectsOutputToContain('The --user option must be a numeric user id.')
            ->assertExitCode(1);

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    #[Test]
    public function it_rejects_nonexistent_user_ids(): void
    {
        Queue::fake();

        $this->artisan('working-memory:consolidate', ['--user' => '999999'])
            ->expectsOutputToContain('User 999999 does not exist.')
            ->assertExitCode(1);

        Queue::assertNotPushed(ConsolidateWorkingMemory::class);
    }

    #[Test]
    public function it_registers_a_daily_consolidation_schedule(): void
    {
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(function ($scheduledEvent): bool {
            $command = (string) ($scheduledEvent->command ?? '');

            return str_contains($command, 'working-memory:consolidate');
        });

        $this->assertNotNull($event);

        $reflection = new ReflectionClass($event);
        $property = $reflection->getProperty('expression');
        $property->setAccessible(true);
        $this->assertSame('45 2 * * *', (string) $property->getValue($event));
    }

    private function matchesJobScope(
        ConsolidateWorkingMemory $job,
        int $expectedUserId,
        string $expectedScopeType,
        string $expectedScopeKey
    ): bool {
        return $this->readJobProperty($job, 'userId') === $expectedUserId
            && $this->readJobProperty($job, 'scopeType') === $expectedScopeType
            && $this->readJobProperty($job, 'scopeKey') === $expectedScopeKey;
    }

    private function readJobProperty(ConsolidateWorkingMemory $job, string $property): mixed
    {
        try {
            $reflection = new ReflectionClass($job);
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);

            return $prop->getValue($job);
        } catch (ReflectionException) {
            return null;
        }
    }
}

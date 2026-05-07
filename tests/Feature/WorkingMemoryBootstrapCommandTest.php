<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\ConsolidateWorkingMemory;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_compaction_jobs_synchronously_then_consolidates(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $meetings = Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        $expectedIds = $meetings->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSyncTimes(SynthesizeMeetingCompactionJob::class, count($expectedIds));
        foreach ($expectedIds as $id) {
            Bus::assertDispatchedSync(
                SynthesizeMeetingCompactionJob::class,
                fn (SynthesizeMeetingCompactionJob $job): bool => $job->thoughtId === $id,
            );
        }
        Bus::assertDispatchedSync(BuildScopeDigestJob::class);
        Bus::assertDispatchedSync(SynthesizeResearchCompactionJob::class);
        Bus::assertDispatched(ConsolidateWorkingMemory::class);
    }

    #[Test]
    public function it_only_dispatches_meeting_jobs_for_meetings_whose_primary_scope_matches(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $inScope = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        // Meeting whose primary scope is project/foo, not project/dezeen.
        $outOfScope = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:foo']],
            'source_metadata' => ['project' => 'foo'],
        ]);
        // Meeting whose primary scope is tag/design-sync (no project), also out of scope.
        $tagOnly = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['design-sync']],
        ]);

        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSyncTimes(SynthesizeMeetingCompactionJob::class, 1);
        Bus::assertDispatchedSync(
            SynthesizeMeetingCompactionJob::class,
            fn (SynthesizeMeetingCompactionJob $job): bool => $job->thoughtId === (string) $inScope->id,
        );
        Bus::assertNotDispatchedSync(
            SynthesizeMeetingCompactionJob::class,
            fn (SynthesizeMeetingCompactionJob $job): bool => in_array(
                $job->thoughtId,
                [(string) $outOfScope->id, (string) $tagOnly->id],
                true,
            ),
        );
    }

    #[Test]
    public function it_requires_a_user_option(): void
    {
        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ])->run();

        $this->assertSame(1, $exit);
    }
}

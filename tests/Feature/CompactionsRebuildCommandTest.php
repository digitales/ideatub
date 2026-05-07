<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionsRebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_only_the_requested_subtype(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'weekly-digest',
        ])->assertExitCode(0);

        Bus::assertDispatchedSync(BuildScopeDigestJob::class);
        Bus::assertNotDispatched(SynthesizeResearchCompactionJob::class);
    }

    #[Test]
    public function it_dispatches_one_meeting_job_per_meeting_thought(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        // Non-meeting thought should be ignored.
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $exit = $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'meeting',
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSyncTimes(SynthesizeMeetingCompactionJob::class, 2);
        Bus::assertNotDispatched(BuildScopeDigestJob::class);
        Bus::assertNotDispatched(SynthesizeResearchCompactionJob::class);
    }

    #[Test]
    public function it_dispatches_research_synth_when_requested(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'research-synth',
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSync(SynthesizeResearchCompactionJob::class);
        Bus::assertNotDispatched(BuildScopeDigestJob::class);
        Bus::assertNotDispatched(SynthesizeMeetingCompactionJob::class);
    }

    #[Test]
    public function it_rejects_unknown_subtypes(): void
    {
        $user = User::factory()->create();

        $exit = $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'bogus',
        ])->run();

        $this->assertSame(1, $exit);
    }
}

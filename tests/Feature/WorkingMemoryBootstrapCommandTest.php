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

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSync(SynthesizeMeetingCompactionJob::class);
        Bus::assertDispatchedSync(BuildScopeDigestJob::class);
        Bus::assertDispatchedSync(SynthesizeResearchCompactionJob::class);
        Bus::assertDispatched(ConsolidateWorkingMemory::class);
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

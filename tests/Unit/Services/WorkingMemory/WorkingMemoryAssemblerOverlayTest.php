<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryAssemblerOverlayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    #[Test]
    public function it_uses_consolidated_canonical_and_populates_overlay_when_incremental_is_newer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Baseline consolidated corpus signal one.',
            'metadata' => ['tags' => ['baseline']],
        ]);

        $builder = app(WorkingMemoryBuilderService::class);
        $consolidated = $builder->buildConsolidated($user->id, 'global', 'global');

        Carbon::setTestNow(Carbon::parse('2026-05-05 12:00:00', 'UTC'));

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Overlay-only incremental thread alpha beta gamma.',
            'metadata' => ['tags' => ['overlay']],
        ]);

        $incremental = $builder->buildIncremental($user->id, 'global', 'global');

        $payload = app(WorkingMemoryAssembler::class)->forScope((int) $user->id, 'global', 'global');

        $this->assertSame($consolidated->summary_markdown, $payload['summary_markdown']);
        $this->assertSame('consolidated', $payload['baseline_build_type']);
        $this->assertSame((float) $consolidated->confidence_score, $payload['confidence_score']);
        $this->assertSame($consolidated->inputs()->count(), $payload['input_count']);

        $this->assertNotSame($incremental->summary_markdown, $payload['summary_markdown']);

        $this->assertNotEmpty($payload['overlay_deltas']);
        $first = $payload['overlay_deltas'][0];
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('detail', $first);
        $this->assertArrayHasKey('since', $first);
        $this->assertStringContainsString('alpha', implode(' ', array_column($payload['overlay_deltas'], 'label')));
    }

    #[Test]
    public function it_returns_empty_overlay_when_only_consolidated_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-05 09:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Only consolidated content.',
            'metadata' => ['tags' => ['solo']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        $payload = app(WorkingMemoryAssembler::class)->forScope((int) $user->id, 'global', 'global');

        $this->assertSame('consolidated', $payload['baseline_build_type']);
        $this->assertSame([], $payload['overlay_deltas']);
    }
}

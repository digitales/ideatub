<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SynthesizeMeetingCompactionJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_compaction_version_for_a_meeting_thought(): void
    {
        // OpenRouter is mocked before the meeting thought is created so the observer's
        // synchronous SynthesizeMeetingCompactionJob dispatch hits the mock; the explicit
        // dispatchSync below exercises the same handler a second time, hence twice().
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->twice()->andReturn(json_encode([
            'summary_markdown' => "## Summary\nWeekly check-in agreed PHP upgrade scope.\n## Decisions\n- Ship DEZ-2819.",
            'structured_sections' => [
                'Summary' => [['text' => 'Weekly check-in agreed PHP upgrade scope.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Decisions' => [['text' => 'Ship DEZ-2819.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Action Items' => [],
                'Risks / Blockers' => [],
                'Open Questions' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Weekly check-in 2026-05-07. Decided to ship DEZ-2819 fix.',
            'metadata' => ['type' => 'meeting', 'tags' => ['scope:project', 'project:dezeen']],
        ]);

        SynthesizeMeetingCompactionJob::dispatchSync($meeting->id);

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:meeting')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('PHP upgrade scope', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_non_meeting_thoughts(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        SynthesizeMeetingCompactionJob::dispatchSync($thought->id);

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:meeting')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

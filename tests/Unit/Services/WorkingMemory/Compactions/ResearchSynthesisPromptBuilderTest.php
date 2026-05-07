<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\ResearchSynthesisPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResearchSynthesisPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_required_sections_and_research_blocks(): void
    {
        $thought = new Thought([
            'content' => 'Postgres MVCC bloat reaches 30% under workload X.',
        ]);
        $thought->id = 'r-1';
        $thought->created_at = Carbon::parse('2026-05-05T08:00:00Z');

        $builder = new ResearchSynthesisPromptBuilder;
        $prompt = $builder->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            thoughts: new Collection([$thought]),
        );

        $this->assertStringContainsString('## Research synthesis task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Source Notes', $prompt);
        $this->assertStringContainsString('Postgres MVCC bloat', $prompt);
        $this->assertStringContainsString('thought:r-1', $prompt);
        $this->assertStringContainsString('[2026-05-05T08:00:00', $prompt);
    }
}

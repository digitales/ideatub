<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\ScopeDigestPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScopeDigestPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_required_sections_and_thought_blocks(): void
    {
        $thoughtA = new Thought([
            'content' => 'Shipped DEZ-2819 hotfix.',
        ]);
        $thoughtA->id = 't-1';
        $thoughtA->created_at = Carbon::parse('2026-05-06T10:00:00Z');

        $thoughtB = new Thought([
            'content' => 'Open question on observability budget.',
        ]);
        $thoughtB->id = 't-2';
        $thoughtB->created_at = Carbon::parse('2026-05-06T12:00:00Z');

        $builder = new ScopeDigestPromptBuilder;
        $prompt = $builder->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            windowStart: Carbon::parse('2026-04-30T00:00:00Z'),
            windowEnd: Carbon::parse('2026-05-07T00:00:00Z'),
            thoughts: new Collection([$thoughtA, $thoughtB]),
        );

        $this->assertStringContainsString('## Scope digest task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Recent Changes', $prompt);
        $this->assertStringContainsString('Shipped DEZ-2819 hotfix.', $prompt);
        $this->assertStringContainsString('thought:t-1', $prompt);
        $this->assertStringContainsString('thought:t-2', $prompt);
    }
}

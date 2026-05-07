<?php

namespace Tests\Unit\Services\WorkingMemory\Composer;

use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryComposerPromptBuilderTest extends TestCase
{
    #[Test]
    public function it_includes_required_sections_scope_and_signal_blocks(): void
    {
        $builder = new WorkingMemoryComposerPromptBuilder;

        $prompt = $builder->build([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 'thought-1',
                    'content' => 'DEZ-2819: comments-endpoint.php missing from committed plugin tree.',
                    'created_at' => '2026-05-02T09:00:00Z',
                    'references' => [
                        ['type' => 'thought', 'url' => '/thoughts/thought-1', 'label' => 'DEZ-2819 bug scan'],
                    ],
                ],
            ],
            'compactions' => [
                [
                    'version_id' => 'compaction-1',
                    'subtype' => 'meeting',
                    'summary_markdown' => "## Summary\nWeekly check-in agreed PHP upgrade scope.",
                    'created_at' => '2026-05-01T15:00:00Z',
                    'references' => [
                        ['type' => 'compaction', 'url' => '/memory/project/dezeen/compactions/compaction-1', 'label' => 'Weekly check-in 2026-05-01'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('## Working memory composition task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Current Focus', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Recent Changes', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Next Actions', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Source Notes', $prompt);
        $this->assertStringContainsString('compaction:meeting', $prompt);
        $this->assertStringContainsString('DEZ-2819', $prompt);
        $this->assertStringContainsString('Return JSON', $prompt);
    }

    #[Test]
    public function it_truncates_input_to_configured_max_chars(): void
    {
        config(['working_memory.authoring_max_prompt_input_chars' => 500]);
        $builder = new WorkingMemoryComposerPromptBuilder;

        $longContent = str_repeat('A', 5000);
        $prompt = $builder->build([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                ['thought_id' => 't', 'content' => $longContent, 'created_at' => '2026-05-07T09:00:00Z', 'references' => []],
            ],
            'compactions' => [],
        ]);

        $this->assertLessThan(2000, mb_strlen($prompt));
    }
}

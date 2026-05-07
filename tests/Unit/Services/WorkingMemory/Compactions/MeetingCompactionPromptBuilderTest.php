<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MeetingCompactionPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_meeting_thought_content_and_required_sections(): void
    {
        $thought = Thought::factory()->create([
            'content' => 'Standup 2026-05-07. Decided to ship DEZ-2819 hotfix Friday.',
            'metadata' => ['type' => 'meeting', 'tags' => ['client:dezeen']],
        ]);

        $builder = new MeetingCompactionPromptBuilder;
        $prompt = $builder->build($thought);

        $this->assertStringContainsString('## Meeting compaction task', $prompt);
        $this->assertStringContainsString('Summary', $prompt);
        $this->assertStringContainsString('Decisions', $prompt);
        $this->assertStringContainsString('Action Items', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Standup 2026-05-07', $prompt);
        $this->assertStringContainsString('Return JSON', $prompt);
    }
}

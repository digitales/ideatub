<?php

namespace Tests\Unit\Services;

use App\Models\Thought;
use App\Services\Meetings\MeetingPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingPromptBuilderTest extends TestCase
{
    #[Test]
    public function prompt_builder_includes_core_and_custom_categories_and_limits_related_context(): void
    {
        $meeting = Thought::factory()->make([
            'content' => "Sprint planning transcript\nAction items and decisions.",
            'metadata' => ['type' => 'meeting', 'tags' => ['meeting:weekly-sync']],
        ]);
        $related = Thought::factory()->count(5)->make();

        $payload = app(MeetingPromptBuilder::class)->buildMeetingBriefPrompt(
            meeting: $meeting,
            instructions: 'Prioritize concise action items.',
            transcriptText: "Speaker A: update\nSpeaker B: blocker",
            intensity: 'standard',
            coreCategories: ['decisions', 'action_items', 'risks', 'blockers', 'follow_ups'],
            customCategories: ['budget', 'hiring'],
            outputShape: ['sections' => ['summary', 'decisions_overview']],
            relatedThoughts: $related,
        );

        $this->assertStringContainsString('Sprint planning transcript', $payload);
        $this->assertStringContainsString('Prioritize concise action items.', $payload);
        $this->assertStringContainsString('decisions, action_items, risks, blockers, follow_ups', $payload);
        $this->assertStringContainsString('budget, hiring', $payload);
        $this->assertStringContainsString('summary, decisions_overview', $payload);
        $this->assertLessThanOrEqual(3, preg_match_all('/Related meeting \d+:/', $payload));
    }

    #[Test]
    public function prompt_builder_truncates_long_transcript_text(): void
    {
        $meeting = Thought::factory()->make([
            'content' => 'Weekly sync',
            'metadata' => ['type' => 'meeting'],
        ]);
        $longTranscript = str_repeat('a', MeetingPromptBuilder::MAX_TRANSCRIPT_CHARS + 100);

        $payload = app(MeetingPromptBuilder::class)->buildMeetingBriefPrompt(
            meeting: $meeting,
            instructions: '',
            transcriptText: $longTranscript,
            intensity: 'concise',
            coreCategories: ['decisions', 'action_items'],
            customCategories: [],
            outputShape: ['summary'],
            relatedThoughts: [],
        );

        $this->assertStringContainsString('## Transcript', $payload);
        $this->assertStringContainsString('…', $payload);
    }
}

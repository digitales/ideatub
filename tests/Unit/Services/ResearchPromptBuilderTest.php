<?php

namespace Tests\Unit\Services;

use App\Models\Thought;
use App\Services\Research\ResearchPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResearchPromptBuilderTest extends TestCase
{
    #[Test]
    public function prompt_builder_caps_related_context_and_applies_output_sections(): void
    {
        $idea = Thought::factory()->make([
            'content' => 'Investigate a tool for founders doing market validation.',
            'metadata' => ['type' => 'idea', 'tags' => ['founders', 'market', 'saas']],
        ]);
        $related = Thought::factory()->count(5)->make();

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: $idea,
            instructions: 'Focus on practical validation.',
            contextOptions: ['idea', 'tags', 'related_thoughts'],
            outputShape: ['summary', 'risks', 'next_steps'],
            intensity: 'standard',
            relatedThoughts: $related,
        );

        $this->assertStringContainsString('Investigate a tool', $payload);
        $this->assertStringContainsString('Focus on practical validation.', $payload);
        $this->assertStringContainsString('summary', $payload);
        $this->assertStringContainsString('risks', $payload);
        $this->assertStringContainsString('next_steps', $payload);
        $this->assertLessThanOrEqual(3, substr_count($payload, 'Related thought'));
    }

    #[Test]
    public function prompt_builder_limits_idea_tags_to_ten(): void
    {
        $tags = array_map(fn (int $i) => "tag-{$i}", range(1, 15));
        $idea = Thought::factory()->make([
            'content' => 'Short idea.',
            'metadata' => ['type' => 'idea', 'tags' => $tags],
        ]);

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: $idea,
            instructions: '',
            contextOptions: ['idea', 'tags'],
            outputShape: ['summary'],
            intensity: 'concise',
            relatedThoughts: [],
        );

        $this->assertSame(10, substr_count($payload, 'tag-'));
        $this->assertStringNotContainsString('tag-11', $payload);
    }

    #[Test]
    public function prompt_builder_truncates_related_thought_excerpts(): void
    {
        $long = str_repeat('a', ResearchPromptBuilder::RELATED_EXCERPT_MAX_CHARS + 80);
        $related = Thought::factory()->make(['content' => $long]);

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: Thought::factory()->make(['content' => 'Idea', 'metadata' => ['type' => 'idea']]),
            instructions: '',
            contextOptions: ['idea', 'related_thoughts'],
            outputShape: ['summary'],
            intensity: 'standard',
            relatedThoughts: [$related],
        );

        $this->assertStringContainsString('…', $payload);
        $matched = preg_match('/Related thought 1:\s*(?<body>[^\n]+)/u', $payload, $matches);
        $this->assertSame(1, $matched);
        $body = trim($matches['body'] ?? '');
        $this->assertLessThanOrEqual(ResearchPromptBuilder::RELATED_EXCERPT_MAX_CHARS, mb_strlen($body));
    }

    #[Test]
    public function prompt_builder_includes_existing_research_when_provided(): void
    {
        $idea = Thought::factory()->make([
            'content' => 'Expand the MVP scope.',
            'metadata' => ['type' => 'idea'],
        ]);

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: $idea,
            instructions: 'Be brief.',
            contextOptions: ['idea', 'existing_research'],
            outputShape: ['sections' => ['summary']],
            intensity: 'thorough',
            relatedThoughts: [],
            existingResearchContent: 'Prior note about competitors.',
        );

        $this->assertStringContainsString('Prior note about competitors.', $payload);
    }

    #[Test]
    public function prompt_builder_falls_back_to_summary_for_blank_flat_output_sections(): void
    {
        $idea = Thought::factory()->make([
            'content' => 'Validate a new idea.',
            'metadata' => ['type' => 'idea'],
        ]);

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: $idea,
            instructions: '',
            contextOptions: ['idea'],
            outputShape: ['', '   '],
            intensity: 'standard',
            relatedThoughts: [],
        );

        $this->assertStringContainsString('**summary**', $payload);
        $this->assertStringNotContainsString('**, **', $payload);
    }

    #[Test]
    public function prompt_builder_falls_back_to_summary_for_blank_nested_output_sections(): void
    {
        $idea = Thought::factory()->make([
            'content' => 'Validate a new idea.',
            'metadata' => ['type' => 'idea'],
        ]);

        $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
            idea: $idea,
            instructions: '',
            contextOptions: ['idea'],
            outputShape: ['sections' => ['', '   ']],
            intensity: 'standard',
            relatedThoughts: [],
        );

        $this->assertStringContainsString('**summary**', $payload);
        $this->assertStringNotContainsString('**, **', $payload);
    }
}

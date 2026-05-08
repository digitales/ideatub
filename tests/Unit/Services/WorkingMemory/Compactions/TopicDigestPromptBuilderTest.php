<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TopicDigestPromptBuilderTest extends TestCase
{
    #[Test]
    public function it_renders_required_sections_topic_block_and_thought_blocks(): void
    {
        $thoughtA = new Thought([
            'content' => 'Pricing tier B regression observed in dezeen onboarding.',
        ]);
        $thoughtA->id = 't-1';
        $thoughtA->created_at = Carbon::parse('2026-05-05T08:00:00Z');

        $thoughtB = new Thought([
            'content' => 'Pricing tier C unaffected; flag rollback complete.',
        ]);
        $thoughtB->id = 't-2';
        $thoughtB->created_at = Carbon::parse('2026-05-06T10:30:00Z');

        $thoughts = new Collection([$thoughtA, $thoughtB]);

        $prompt = (new TopicDigestPromptBuilder)->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            topic: 'pricing',
            thoughts: $thoughts,
        );

        $this->assertStringContainsString('## Topic digest task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Topic: pricing', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('thought:t-1', $prompt);
        $this->assertStringContainsString('thought:t-2', $prompt);
        $this->assertStringContainsString('[2026-05-05T08:00:00', $prompt);
        $this->assertStringContainsString('Pricing tier B regression observed in dezeen onboarding.', $prompt);
        $this->assertStringContainsString('Pricing tier C unaffected; flag rollback complete.', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_renders_a_placeholder_when_no_thoughts_are_supplied(): void
    {
        $prompt = (new \App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder)->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            topic: 'pricing',
            thoughts: new \Illuminate\Support\Collection,
        );

        $this->assertStringContainsString('## Topic digest task', $prompt);
        $this->assertStringContainsString('Topic: pricing', $prompt);
        $this->assertStringContainsString('_No tagged captures._', $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_caps_the_prompt_to_the_configured_max_input_chars(): void
    {
        config()->set('working_memory.authoring_max_prompt_input_chars', 200);

        $thought = new \App\Models\Thought([
            'content' => str_repeat('long content ', 200),
        ]);
        $thought->id = 't-1';
        $thought->created_at = \Illuminate\Support\Carbon::parse('2026-05-05T08:00:00Z');

        $prompt = (new \App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder)->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            topic: 'pricing',
            thoughts: new \Illuminate\Support\Collection([$thought]),
        );

        $this->assertLessThanOrEqual(200, mb_strlen($prompt));
    }
}

<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryEvidencePackBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryEvidencePackBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prefers_internal_thought_links_and_falls_back_to_source_refs(): void
    {
        $user = User::factory()->create();

        $linkedThought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Connected thought with internal route.',
            'created_at' => Carbon::parse('2026-05-05 12:00:00', 'UTC'),
        ]);

        $fallbackOnlyThought = Thought::make([
            'user_id' => $user->id,
            'content' => 'Imported note without internal thought id.',
            'source_metadata' => [
                'file_path' => 'docs/superpowers/specs/example.md',
                'doc_type' => 'spec',
            ],
            'created_at' => Carbon::parse('2026-05-05 11:00:00', 'UTC'),
        ]);

        $pack = app(WorkingMemoryEvidencePackBuilder::class)->build(
            $user->id,
            'global',
            'global',
            collect([$linkedThought, $fallbackOnlyThought])
        );

        $linkedSignalRef = $pack['signals'][0]['references'][0] ?? null;
        $fallbackSignalRef = $pack['signals'][1]['references'][0] ?? null;

        $this->assertNotNull($linkedSignalRef);
        $this->assertSame('thought', $linkedSignalRef['type']);
        $this->assertStringContainsString('/thoughts/', $linkedSignalRef['url']);
        $this->assertNotNull($fallbackSignalRef);
        $this->assertSame('source', $fallbackSignalRef['type']);
        $this->assertSame('docs/superpowers/specs/example.md', $fallbackSignalRef['url']);
    }

    #[Test]
    public function it_builds_scope_specific_signal_set_for_tag_scope(): void
    {
        $user = User::factory()->create();

        $matching = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Focus work for AI scope.',
            'metadata' => ['tags' => [' AI ', 'memory']],
            'created_at' => Carbon::parse('2026-05-05 12:00:00', 'UTC'),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Roadmap item not in selected scope.',
            'metadata' => ['tags' => ['roadmap']],
            'created_at' => Carbon::parse('2026-05-05 13:00:00', 'UTC'),
        ]);

        $allThoughts = Thought::query()->where('user_id', $user->id)->get();

        $pack = app(WorkingMemoryEvidencePackBuilder::class)->build(
            $user->id,
            'tag',
            'ai',
            $allThoughts
        );

        $this->assertSame('tag', $pack['scope_type']);
        $this->assertSame('ai', $pack['scope_key']);
        $this->assertCount(1, $pack['signals']);
        $this->assertSame((string) $matching->id, $pack['signals'][0]['thought_id']);
    }

    #[Test]
    public function it_only_emits_signals_for_the_requested_user_id(): void
    {
        $selectedUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $selectedThought = Thought::factory()->create([
            'user_id' => $selectedUser->id,
            'content' => 'Selected user thought.',
            'created_at' => Carbon::parse('2026-05-05 14:00:00', 'UTC'),
        ]);

        Thought::factory()->create([
            'user_id' => $otherUser->id,
            'content' => 'Other user thought.',
            'created_at' => Carbon::parse('2026-05-05 15:00:00', 'UTC'),
        ]);

        $mixedThoughts = Thought::query()
            ->whereIn('user_id', [$selectedUser->id, $otherUser->id])
            ->get();

        $pack = app(WorkingMemoryEvidencePackBuilder::class)->build(
            $selectedUser->id,
            'global',
            'global',
            $mixedThoughts
        );

        $this->assertCount(1, $pack['signals']);
        $this->assertSame((string) $selectedThought->id, $pack['signals'][0]['thought_id']);
    }
}

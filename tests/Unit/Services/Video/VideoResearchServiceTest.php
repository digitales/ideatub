<?php

namespace Tests\Unit\Services\Video;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\VideoResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VideoResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function videoRoot(User $user, array $metadataExtra = []): Thought
    {
        return Thought::query()->create([
            'content' => 'YouTube video placeholder',
            'embedding' => null,
            'metadata' => array_merge([
                'type' => 'video',
                'video_id' => 'vid123',
                'video_url' => 'https://www.youtube.com/watch?v=vid123',
                'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_AVAILABLE,
                'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_YOUTUBE,
                'research_pending' => true,
                VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING => true,
            ], $metadataExtra),
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => null,
        ]);
    }

    private function transcriptChild(Thought $root, User $user, string $text): Thought
    {
        return Thought::query()->create([
            'content' => "## Transcript\n\n{$text}",
            'embedding' => null,
            'metadata' => ['video_section_type' => 'transcript'],
            'user_id' => $user->id,
            'source' => 'video',
            'source_metadata' => null,
            'parent_id' => $root->id,
        ]);
    }

    /**
     * Post-research enrichment: same OpenRouter paths as normal thought capture (metadata + embedding).
     *
     * @param  array<string, mixed>  $extractReturn
     */
    private function mockOpenRouterEnrichment(MockInterface $mock, array $extractReturn = ['tags' => ['topic-one'], 'people' => [], 'action_items' => []]): void
    {
        $mock->shouldReceive('extractMetadata')->once()->andReturn($extractReturn);
        $mock->shouldReceive('embed')->once()->andReturn(array_fill(0, 1536, 0.02));
    }

    public function test_success_saves_research_with_fixed_headings_and_updates_latest_pointer(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, [
            'research_thought_id' => null,
        ]);
        $this->transcriptChild($root, $user, 'Hello from transcript');

        $rawModel = "## Summary\n\nS.\n## Key Points\n\n- K\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nSrc";
        $this->mock(OpenRouterService::class, function ($mock) use ($rawModel): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn($rawModel);
            $this->mockOpenRouterEnrichment($mock, [
                'tags' => ['anthropic', 'security'],
                'people' => ['Ada'],
                'action_items' => ['Review policy'],
            ]);
        });

        $service = app(VideoResearchService::class);
        $research = $service->runAndSaveForVideoRoot($root->fresh());

        $root->refresh();
        $this->assertSame($research->id, $root->metadata['research_thought_id']);
        foreach (['## Summary', '## Key Points', '## Positives', '## Negatives', '## Source Notes'] as $heading) {
            $this->assertStringContainsString($heading, $research->content);
        }
        $this->assertSame('research', $research->metadata['type'] ?? null);
        $this->assertSame($root->id, $research->metadata['video_thought_id'] ?? null);
        $this->assertNotNull($research->embedding);
        $tags = $research->metadata['tags'] ?? [];
        $this->assertContains('research', $tags);
        $this->assertContains('video', $tags);
        $this->assertContains('anthropic', $tags);
        $this->assertContains('security', $tags);
        $this->assertSame(['Ada'], $research->metadata['people'] ?? []);
        $this->assertSame(['Review policy'], $research->metadata['action_items'] ?? []);
        $sm = $research->source_metadata ?? [];
        $this->assertSame($root->id, $sm['video_thought_id'] ?? null);
        $this->assertSame('vid123', $sm['video_id'] ?? null);
        $this->assertTrue($sm['transcript_context_available'] ?? false);
        $this->assertFalse($root->metadata['research_pending'] ?? false);
        $this->assertArrayNotHasKey(VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING, $root->metadata);
        $this->assertArrayNotHasKey(VideoCaptureService::META_VIDEO_TRANSCRIPT_READY_FOR_RESEARCH, $root->metadata);
    }

    public function test_second_run_chains_prior_research_via_parent_research_thought_id(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user);

        $firstResearch = Thought::query()->create([
            'content' => 'old',
            'embedding' => null,
            'metadata' => Thought::normalizeMetadataTags([
                'type' => 'research',
                'video_thought_id' => $root->id,
                'tags' => ['research', 'video'],
            ]),
            'user_id' => $user->id,
            'source' => 'research',
            'source_metadata' => [
                'video_thought_id' => $root->id,
                'video_id' => 'vid123',
                'transcript_context_available' => true,
            ],
            'parent_id' => null,
        ]);

        $root->update([
            'metadata' => Thought::normalizeMetadataTags(array_merge(
                $root->metadata ?? [],
                ['research_thought_id' => $firstResearch->id]
            )),
        ]);
        $this->transcriptChild($root->fresh(), $user, 'T');

        $rawModel = "## Summary\n\nS2\n## Key Points\n\nK2\n## Positives\n\nP2\n## Negatives\n\nN2\n## Source Notes\n\nSN2";
        $this->mock(OpenRouterService::class, function ($mock) use ($rawModel): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn($rawModel);
            $this->mockOpenRouterEnrichment($mock);
        });

        $service = app(VideoResearchService::class);
        $second = $service->runAndSaveForVideoRoot($root->fresh());

        $this->assertSame($firstResearch->id, $second->metadata['parent_research_thought_id'] ?? null);
        $root->refresh();
        $this->assertSame($second->id, $root->metadata['research_thought_id']);
        $this->assertNotSame($firstResearch->id, $second->id);
        $this->assertSame('old', $firstResearch->fresh()->content);
    }

    public function test_transcript_unavailable_marks_limited_context_in_source_metadata(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, [
            'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_UNAVAILABLE,
            'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
        ]);

        $rawModel = "## Summary\n\nS\n## Key Points\n\nK\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nSN";
        $this->mock(OpenRouterService::class, function ($mock) use ($rawModel): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->with(Mockery::on(function (string $p): bool {
                    return str_contains($p, 'transcript') && stripos($p, 'limited') !== false;
                }))
                ->andReturn($rawModel);
            $this->mockOpenRouterEnrichment($mock);
        });

        $service = app(VideoResearchService::class);
        $research = $service->runAndSaveForVideoRoot($root->fresh());

        $sm = $research->source_metadata ?? [];
        $this->assertFalse($sm['transcript_context_available'] ?? true);
    }

    public function test_failure_clears_research_pending_without_moving_latest_pointer(): void
    {
        $user = User::factory()->create();
        $priorId = (string) Str::uuid();
        $root = $this->videoRoot($user, [
            'research_thought_id' => $priorId,
        ]);
        $this->transcriptChild($root, $user, 'T');

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andThrow(new \RuntimeException('OpenRouter down'));
        });

        $service = app(VideoResearchService::class);

        try {
            $service->runAndSaveForVideoRoot($root->fresh());
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('OpenRouter down', $e->getMessage());
        }

        $root->refresh();
        $this->assertSame($priorId, $root->metadata['research_thought_id']);
        $this->assertFalse((bool) ($root->metadata['research_pending'] ?? false));
        $this->assertArrayNotHasKey(VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING, $root->metadata);
        $this->assertSame(0, Thought::query()->where('metadata->type', 'research')->where('metadata->video_thought_id', $root->id)->count());
    }

    public function test_normalizes_missing_sections_by_appending_placeholders(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, ['research_thought_id' => null]);
        $this->transcriptChild($root, $user, 'T');

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn('No structure here.');
            $this->mockOpenRouterEnrichment($mock);
        });

        $service = app(VideoResearchService::class);
        $research = $service->runAndSaveForVideoRoot($root->fresh());

        foreach (['## Summary', '## Key Points', '## Positives', '## Negatives', '## Source Notes'] as $heading) {
            $this->assertStringContainsString($heading, $research->content);
        }
    }

    public function test_saves_body_with_required_headings_in_canonical_order(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, ['research_thought_id' => null]);
        $this->transcriptChild($root, $user, 'T');

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn(
                "## Negatives\n\nN\n## Summary\n\nS\n## Source Notes\n\nSN\n## Key Points\n\nK\n## Positives\n\nP"
            );
            $this->mockOpenRouterEnrichment($mock);
        });

        $research = app(VideoResearchService::class)->runAndSaveForVideoRoot($root->fresh());

        $expected = [
            '## Summary',
            '## Key Points',
            '## Positives',
            '## Negatives',
            '## Source Notes',
        ];
        $positions = array_map(fn (string $heading): int|false => strpos($research->content, $heading), $expected);

        $this->assertSame($expected, array_values(array_filter(explode("\n", $research->content), fn (string $line): bool => str_starts_with($line, '## '))));
        $this->assertNotFalse($positions[0]);
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3] && $positions[3] < $positions[4]);
    }

    public function test_limited_transcript_context_is_deterministically_added_to_source_notes(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, [
            'transcript_status' => VideoCaptureService::TRANSCRIPT_STATUS_FAILED,
            'transcript_source' => VideoCaptureService::TRANSCRIPT_SOURCE_NONE,
            'research_thought_id' => null,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andReturn("## Summary\n\nS\n## Key Points\n\nK\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nOnly generic notes.");
            $this->mockOpenRouterEnrichment($mock);
        });

        $research = app(VideoResearchService::class)->runAndSaveForVideoRoot($root->fresh());

        $this->assertStringContainsString('Transcript context was unavailable or limited when this research ran.', $research->content);
        $this->assertStringContainsString('transcript_status: failed', $research->content);
    }

    public function test_interleaving_pointer_update_during_model_call_is_preserved_when_saving(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, ['research_thought_id' => null]);
        $this->transcriptChild($root, $user, 'Hello from transcript');

        $interleaved = Thought::query()->create([
            'content' => 'new latest before save',
            'embedding' => null,
            'metadata' => Thought::normalizeMetadataTags([
                'type' => 'research',
                'video_thought_id' => $root->id,
                'tags' => ['research', 'video'],
            ]),
            'user_id' => $user->id,
            'source' => 'research',
            'source_metadata' => [
                'video_thought_id' => $root->id,
                'video_id' => 'vid123',
                'transcript_context_available' => true,
            ],
            'parent_id' => null,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($root, $interleaved): void {
            $mock->shouldReceive('researchFromPrompt')
                ->once()
                ->andReturnUsing(function () use ($root, $interleaved): string {
                    $fresh = $root->fresh();
                    $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
                    $meta['research_thought_id'] = $interleaved->id;
                    $meta['concurrent_marker'] = 'preserve-me';
                    $fresh->update([
                        'metadata' => Thought::normalizeMetadataTags($meta),
                    ]);

                    return "## Summary\n\nS\n## Key Points\n\nK\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nSN";
                });
            $this->mockOpenRouterEnrichment($mock);
        });

        $research = app(VideoResearchService::class)->runAndSaveForVideoRoot($root->fresh());

        $root->refresh();
        $this->assertSame($interleaved->id, $research->metadata['parent_research_thought_id'] ?? null);
        $this->assertSame('preserve-me', $root->metadata['concurrent_marker'] ?? null);
        $this->assertSame($research->id, $root->metadata['research_thought_id'] ?? null);
    }

    public function test_merges_tags_from_video_root_metadata(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, [
            'research_thought_id' => null,
            'tags' => ['Youtube', 'mvp'],
        ]);
        $this->transcriptChild($root, $user, 'T');

        $rawModel = "## Summary\n\nS\n## Key Points\n\nK\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nSN";
        $this->mock(OpenRouterService::class, function ($mock) use ($rawModel): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn($rawModel);
            $this->mockOpenRouterEnrichment($mock, ['tags' => [], 'people' => [], 'action_items' => []]);
        });

        $research = app(VideoResearchService::class)->runAndSaveForVideoRoot($root->fresh());
        $tags = $research->metadata['tags'] ?? [];
        $this->assertContains('research', $tags);
        $this->assertContains('video', $tags);
        $this->assertContains('youtube', $tags);
        $this->assertContains('mvp', $tags);
    }

    public function test_enrichment_openrouter_failures_still_save_research(): void
    {
        $user = User::factory()->create();
        $root = $this->videoRoot($user, ['research_thought_id' => null]);
        $this->transcriptChild($root, $user, 'T');

        $rawModel = "## Summary\n\nS\n## Key Points\n\nK\n## Positives\n\nP\n## Negatives\n\nN\n## Source Notes\n\nSN";
        $this->mock(OpenRouterService::class, function ($mock) use ($rawModel): void {
            $mock->shouldReceive('researchFromPrompt')->once()->andReturn($rawModel);
            $mock->shouldReceive('extractMetadata')->once()->andThrow(new \RuntimeException('metadata down'));
            $mock->shouldReceive('embed')->once()->andThrow(new \RuntimeException('embed down'));
        });

        $research = app(VideoResearchService::class)->runAndSaveForVideoRoot($root->fresh());
        $tags = $research->metadata['tags'] ?? [];
        $this->assertContains('research', $tags);
        $this->assertContains('video', $tags);
        $this->assertNull($research->embedding);
    }
}

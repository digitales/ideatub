<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ThoughtCaptureResearchTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_sets_metadata_title_from_section_title_for_research(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldReceive('extractMetadata')->andReturn([
            'type' => 'research',
            'tags' => ['test'],
        ]);

        $service = app(ThoughtCaptureService::class);
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('openRouter');
        $prop->setAccessible(true);
        $prop->setValue($service, $openRouter);

        $result = $service->create([
            'content' => "# Section Title\n\nResearch body with enough words to trigger chunking. ".str_repeat('word ', 100),
            'user_id' => $user->id,
            'source' => 'mcp',
            'doc_type' => 'research',
            'plan_slug' => 'test-research',
            'source_metadata' => ['section_title' => 'My Research Paper'],
        ]);

        $root = $result['root'] ?? $result['thought'] ?? null;
        $this->assertNotNull($root);
        $this->assertSame('My Research Paper', $root->fresh()->metadata['title']);
    }

    public function test_capture_does_not_overwrite_existing_metadata_title(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldReceive('extractMetadata')->andReturn([
            'type' => 'research',
            'tags' => ['test'],
            'title' => 'AI-Generated Title',
        ]);

        $service = app(ThoughtCaptureService::class);
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('openRouter');
        $prop->setAccessible(true);
        $prop->setValue($service, $openRouter);

        $result = $service->create([
            'content' => "# Section Title\n\nResearch body with enough words to trigger chunking. ".str_repeat('word ', 100),
            'user_id' => $user->id,
            'source' => 'mcp',
            'doc_type' => 'research',
            'plan_slug' => 'test-research',
            'source_metadata' => ['section_title' => 'Should Not Override'],
        ]);

        $root = $result['root'] ?? $result['thought'] ?? null;
        $this->assertNotNull($root);
        $this->assertSame('AI-Generated Title', $root->fresh()->metadata['title']);
    }
}

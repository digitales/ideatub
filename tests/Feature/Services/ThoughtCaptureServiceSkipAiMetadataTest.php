<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ThoughtCaptureServiceSkipAiMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_skip_ai_metadata_bypasses_extract_metadata_but_still_embeds(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldNotReceive('extractMetadata');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $service = $this->app->make(ThoughtCaptureService::class);
        $result = $service->create([
            'content' => 'hello',
            'user_id' => $user->id,
            'source' => 'upload',
            'skip_ai_metadata' => true,
        ]);

        $this->assertSame([], $result['thought']->metadata['tags'] ?? []);
    }

    public function test_skip_ai_metadata_false_still_calls_extract_metadata(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldReceive('extractMetadata')->once()->andReturn(['tags' => ['ai-tag']]);
        $this->app->instance(OpenRouterService::class, $openRouter);

        $this->app->make(ThoughtCaptureService::class)->create([
            'content' => 'hello',
            'user_id' => $user->id,
            'source' => 'web',
        ]);
    }

    public function test_skip_ai_metadata_still_applies_server_controlled_tags(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->once()->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldNotReceive('extractMetadata');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $result = $this->app->make(ThoughtCaptureService::class)->create([
            'content' => 'hello',
            'user_id' => $user->id,
            'source' => 'upload',
            'skip_ai_metadata' => true,
            'plan_slug' => 'alpha',
            'doc_type' => 'spec',
            'extra_tags' => ['folder:docs'],
        ]);

        $tags = $result['thought']->metadata['tags'] ?? [];
        $this->assertContains('spec:alpha', $tags);
        $this->assertContains('folder:docs', $tags);
    }

    public function test_skip_ai_metadata_is_honoured_in_the_chunked_path(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldNotReceive('extractMetadata');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $section = str_repeat('word ', 400);
        $content = "# First\n\n{$section}\n\n## Second\n\n{$section}\n\n## Third\n\n{$section}";

        $result = $this->app->make(ThoughtCaptureService::class)->create([
            'content' => $content,
            'user_id' => $user->id,
            'source' => 'upload',
            'skip_ai_metadata' => true,
        ]);

        $this->assertTrue($result['chunked']);
        $this->assertNotNull($result['root'] ?? null);
    }
}

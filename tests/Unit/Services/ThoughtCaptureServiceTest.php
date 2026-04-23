<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\MetadataSanitiser;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtChunkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThoughtCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_merges_idea_metadata_into_thought_metadata(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.1);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')
            ->once()
            ->with('My idea')
            ->andReturn($fakeEmbedding);
        $openRouter->shouldReceive('extractMetadata')
            ->once()
            ->with('My idea')
            ->andReturn(['tags' => []]);

        $chunking = new ThoughtChunkingService;
        $sanitiser = new MetadataSanitiser;
        $service = new ThoughtCaptureService($openRouter, $chunking, $sanitiser);

        $result = $service->create([
            'content' => 'My idea',
            'user_id' => $user->id,
            'source' => 'web',
            'idea_metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-03-14',
            ],
        ]);

        $this->assertArrayHasKey('thought', $result);
        $this->assertFalse($result['chunked']);
        $thought = $result['thought'];

        $this->assertSame('idea', $thought->metadata['type']);
        $this->assertFalse($thought->metadata['completed']);
        $this->assertSame('2025-03-14', $thought->metadata['logged_date']);
    }
}

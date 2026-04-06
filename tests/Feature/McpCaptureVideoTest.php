<?php

namespace Tests\Feature;

use App\Jobs\FetchVideoTranscript;
use App\Jobs\RunVideoResearch;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\Email\EmailLinkExtractor;
use App\Services\OpenRouterService;
use App\Services\Video\VideoCaptureService;
use App\Services\Video\VideoThoughtContentBuilder;
use App\Services\Video\YouTubeOEmbedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpCaptureVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'www.youtube.com/oembed*' => Http::response([
                'title' => 'Stub video title',
                'author_name' => 'Stub channel',
            ], 200),
        ]);
    }

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('m', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    public function test_capture_video_happy_path_queues_transcript_fetch(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => ['url' => $url],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.video_id', 'dQw4w9WgXcQ');
        $response->assertJsonPath('result.transcript_status', VideoCaptureService::TRANSCRIPT_STATUS_PENDING);
        $response->assertJsonPath('result.research_pending', false);
        $response->assertJsonMissingPath('result.warning');

        $id = $response->json('result.id');
        $this->assertIsString($id);
        $video = Thought::query()->whereKey($id)->where('user_id', $user->id)->first();
        $this->assertNotNull($video);
        $this->assertSame('video', $video->source);

        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($id): bool {
            return $job->videoThoughtId === $id && $job->researchNow === false;
        });
    }

    public function test_capture_video_returns_warning_when_fetch_cannot_be_queued(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });
        $this->app->instance(VideoCaptureService::class, new class(app(EmailLinkExtractor::class), app(OpenRouterService::class), app(VideoThoughtContentBuilder::class), app(YouTubeOEmbedService::class)) extends VideoCaptureService
        {
            public function queueTranscriptFetchIfPending(Thought $root, bool $researchNow = false): bool
            {
                return false;
            }
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.transcript_status', VideoCaptureService::TRANSCRIPT_STATUS_PENDING);
        $response->assertJsonPath('result.warning', fn ($warning) => is_string($warning) && $warning !== '');

        $root = Thought::query()->whereKey($response->json('result.id'))->where('user_id', $user->id)->first();
        $this->assertNotNull($root);
        $this->assertSame(VideoCaptureService::TRANSCRIPT_STATUS_PENDING, $root->metadata['transcript_status'] ?? null);
        Queue::assertNothingPushed();
    }

    public function test_capture_video_duplicate_url_returns_same_thought_id(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $params = ['url' => 'https://youtu.be/dQw4w9WgXcQ'];
        $first = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => $params,
        ]);
        $second = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'capture_video',
            'params' => $params,
        ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('result.id'), $second->json('result.id'));
        $this->assertSame(1, Thought::query()->where('user_id', $user->id)->whereNull('parent_id')->where('metadata->type', 'video')->count());
        Queue::assertPushed(FetchVideoTranscript::class, 1);
    }

    public function test_capture_video_with_transcript_does_not_queue_fetch(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->twice()->andReturn($embed);
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'transcript' => "Line one.\nLine two.",
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.transcript_status', VideoCaptureService::TRANSCRIPT_STATUS_MANUAL);
        Queue::assertNothingPushed();

        $root = Thought::query()->whereKey($response->json('result.id'))->where('user_id', $user->id)->first();
        $this->assertNotNull($root);
        $child = Thought::query()->where('parent_id', $root->id)->where('metadata->video_section_type', 'transcript')->first();
        $this->assertNotNull($child);
    }

    public function test_capture_video_research_now_and_source_metadata(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'research_now' => true,
                'source_metadata' => [
                    'project' => 'ideatub',
                    'via' => 'mcp-test',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.research_pending', true);

        $video = Thought::query()->whereKey($response->json('result.id'))->where('user_id', $user->id)->first();
        $this->assertNotNull($video);
        $this->assertTrue($video->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] ?? false);
        $this->assertSame('ideatub', $video->source_metadata['project'] ?? null);
        $this->assertSame('mcp-test', $video->source_metadata['via'] ?? null);

        Queue::assertPushed(FetchVideoTranscript::class, function (FetchVideoTranscript $job) use ($video): bool {
            return $job->videoThoughtId === $video->id && $job->researchNow === true;
        });
        Queue::assertNotPushed(RunVideoResearch::class);
    }

    public function test_capture_video_research_now_clears_pending_markers_when_fetch_cannot_be_queued(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });
        $this->app->instance(VideoCaptureService::class, new class(app(EmailLinkExtractor::class), app(OpenRouterService::class), app(VideoThoughtContentBuilder::class), app(YouTubeOEmbedService::class)) extends VideoCaptureService
        {
            public function queueTranscriptFetchIfPending(Thought $root, bool $researchNow = false): bool
            {
                return false;
            }
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'research_now' => true,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.research_pending', false);
        $response->assertJsonPath('result.warning', 'Transcript fetch could not be queued; the video was saved. Retry transcript fetch later if needed.');

        $video = Thought::query()->whereKey($response->json('result.id'))->where('user_id', $user->id)->first();
        $this->assertNotNull($video);
        $this->assertFalse((bool) ($video->metadata['research_pending'] ?? false));
        $this->assertFalse((bool) ($video->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] ?? false));
        Queue::assertNotPushed(FetchVideoTranscript::class);
        Queue::assertNotPushed(RunVideoResearch::class);
    }

    public function test_capture_video_tools_call_returns_serialized_result(): void
    {
        Queue::fake();
        [$key, $user] = $this->validKeyAndUser();
        $embed = array_fill(0, 1536, 0.04);
        $this->mock(OpenRouterService::class, function ($mock) use ($embed): void {
            $mock->shouldReceive('embed')->once()->andReturn($embed);
        });

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'capture_video',
                'arguments' => [
                    'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertSame('dQw4w9WgXcQ', $payload['video_id'] ?? null);
        $this->assertSame(VideoCaptureService::TRANSCRIPT_STATUS_PENDING, $payload['transcript_status'] ?? null);

        $video = Thought::query()->whereKey($payload['id'])->where('user_id', $user->id)->first();
        $this->assertNotNull($video);
        Queue::assertPushed(FetchVideoTranscript::class, 1);
    }

    public function test_capture_video_invalid_url_returns_json_rpc_error(): void
    {
        Queue::fake();
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_video',
            'params' => ['url' => 'https://example.com/not-youtube'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
        $response->assertJsonPath('error.message', 'Not a recognized YouTube URL.');
        Queue::assertNothingPushed();
    }
}

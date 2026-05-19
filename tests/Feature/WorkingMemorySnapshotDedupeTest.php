<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemorySnapshotDedupeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: User}
     */
    private function createKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('d', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    private function wmBody(string $refreshedAt): string
    {
        return <<<MD
# Working Memory
Last Updated: 2026-05-19 (refreshed at {$refreshedAt})
Scope: Client-level live state

## Current Focus
- Complete the WordPress upgrade on Wednesday 2026-05-20.
MD;
    }

    #[Test]
    public function duplicate_capture_plan_returns_deduplicated_without_new_visible_thought(): void
    {
        [$key, $user] = $this->createKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $params = [
            'content' => $this->wmBody('2026-05-19T01:00:00Z'),
            'plan_slug' => 'client-working-memory-2026-05-19',
            'project' => 'dezeen',
            'tags' => ['working-memory', 'client:dezeen', 'scope:client'],
            'no_chunking' => true,
        ];

        $first = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'capture_plan',
            'params' => $params,
            'id' => 1,
        ], ['x-ideatub-key' => $key]);
        $first->assertOk();
        $firstId = $first->json('result.id');

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('embed')->never();
            $mock->shouldReceive('extractMetadata')->never();
        });

        $params['content'] = $this->wmBody('2026-05-19T02:00:00Z');
        $second = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'capture_plan',
            'params' => $params,
            'id' => 2,
        ], ['x-ideatub-key' => $key]);

        $second->assertOk();
        $second->assertJsonPath('result.deduplicated', true);
        $second->assertJsonPath('result.id', $firstId);

        $this->assertSame(1, Thought::query()
            ->where('user_id', $user->id)
            ->visibleInStream()
            ->count());
    }

    #[Test]
    public function changed_content_supersedes_prior_snapshot(): void
    {
        [$key, $user] = $this->createKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->twice()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->twice()->andReturn(['tags' => []]);
        });

        $baseParams = [
            'plan_slug' => 'client-working-memory-2026-05-19',
            'project' => 'dezeen',
            'tags' => ['working-memory', 'client:dezeen', 'scope:client'],
            'no_chunking' => true,
        ];

        $first = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'capture_plan',
            'params' => array_merge($baseParams, ['content' => $this->wmBody('2026-05-19T01:00:00Z')]),
            'id' => 1,
        ], ['x-ideatub-key' => $key]);
        $firstId = $first->json('result.id');

        $second = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'capture_plan',
            'params' => array_merge($baseParams, [
                'content' => str_replace(
                    'Complete the WordPress upgrade',
                    'Ship the API migration',
                    $this->wmBody('2026-05-19T03:00:00Z')
                ),
            ]),
            'id' => 2,
        ], ['x-ideatub-key' => $key]);

        $second->assertOk();
        $second->assertJsonPath('result.deduplicated', false);
        $this->assertNotSame($firstId, $second->json('result.id'));

        $prior = Thought::find($firstId);
        $this->assertFalse($prior->is_visible_in_stream);
        $this->assertFalse(data_get($prior->source_metadata, 'working_memory.is_current'));

        $this->assertSame(1, Thought::query()
            ->where('user_id', $user->id)
            ->visibleInStream()
            ->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpApiTest extends TestCase
{
    use RefreshDatabase;

    private function validKey(): string
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('a', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return $plain;
    }

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('x', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($plain),
        ]);

        return [$plain, $user];
    }

    public function test_get_mcp_returns_server_info(): void
    {
        $response = $this->getJson('/api/mcp');

        $response->assertStatus(200);
        $response->assertJson([
            'name' => 'ideatub',
            'version' => '1.0',
            'protocol' => 'json-rpc',
            'methods' => ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought', 'capture_plan'],
        ]);
    }

    public function test_post_initialize_returns_capabilities(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.serverInfo.name', 'ideatub');
        $response->assertJsonStructure(['result' => ['capabilities' => ['tools']]]);
    }

    public function test_post_tools_list_returns_tools(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.tools.0.name', 'search_thoughts');
        $response->assertJsonPath('result.tools.1.name', 'browse_recent');
        $response->assertJsonPath('result.tools.2.name', 'thought_stats');
        $response->assertJsonPath('result.tools.3.name', 'capture_thought');
        $response->assertJsonPath('result.tools.4.name', 'capture_plan');
    }

    public function test_post_without_key_returns_401(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_capture_thought_without_source_stores_mcp(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_thought',
            'params' => ['content' => 'A thought from MCP'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.id', fn ($id) => is_string($id) && strlen($id) > 0);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('mcp', $thought->source);
    }

    public function test_capture_thought_with_source_stores_client_source(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_thought',
            'params' => [
                'content' => 'A thought from Claude',
                'source' => 'claude',
            ],
        ]);

        $response->assertStatus(200);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('claude', $thought->source);
    }

    public function test_browse_recent_includes_source_and_source_metadata(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Thought with source',
            'source' => 'chatgpt',
            'source_metadata' => ['client_version' => '1.0'],
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'browse_recent',
            'params' => ['limit' => 5],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.thoughts.0.source', 'chatgpt');
        $response->assertJsonPath('result.thoughts.0.source_metadata.client_version', '1.0');
    }

    public function test_capture_plan_creates_thought_with_source_plan_and_plan_tag(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => ['implementation']]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => '## Chunk 1: Phase 0 — Bootstrap',
                'file_path' => 'docs/superpowers/plans/2026-03-12-tag-and-stream.md',
                'plan_slug' => '2026-03-12-tag-and-stream',
                'section_title' => 'Chunk 1',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.id', fn ($id) => is_string($id) && strlen($id) > 0);
        $response->assertJsonPath('result.plan_slug', '2026-03-12-tag-and-stream');

        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('plan', $thought->source);
        $this->assertSame('docs/superpowers/plans/2026-03-12-tag-and-stream.md', $thought->source_metadata['file_path'] ?? null);
        $this->assertSame('2026-03-12-tag-and-stream', $thought->source_metadata['plan_slug'] ?? null);
        $this->assertSame('Chunk 1', $thought->source_metadata['section_title'] ?? null);
        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('plan:2026-03-12-tag-and-stream', $tags);
        $this->assertContains('implementation', $tags);
    }

    public function test_capture_plan_with_parent_id_links_to_plan_root(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Plan root',
            'source' => 'plan',
        ]);

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => 'Section 2 content',
                'plan_slug' => 'my-plan',
                'parent_id' => $root->id,
            ],
        ]);

        $response->assertStatus(200);
        $thought = Thought::where('user_id', $user->id)->where('parent_id', $root->id)->first();
        $this->assertNotNull($thought);
        $this->assertSame($root->id, $thought->parent_id);
        $this->assertSame('plan', $thought->source);
    }

    public function test_capture_plan_with_invalid_parent_returns_error(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->never();
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => 'Section content',
                'parent_id' => '00000000-0000-0000-0000-000000000000',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
        $response->assertJsonPath('error.message', 'Parent thought not found.');
    }

    public function test_capture_plan_with_doc_type_decision_sets_source_and_tag(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => '## Project spec summary',
                'doc_type' => 'decision',
                'file_path' => 'decisions/project-spec.md',
                'plan_slug' => 'project-spec',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.doc_type', 'decision');

        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('decision', $thought->source);
        $this->assertSame('decisions/project-spec.md', $thought->source_metadata['file_path'] ?? null);
        $this->assertSame('decision', $thought->source_metadata['doc_type'] ?? null);
        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('decision:project-spec', $tags);
    }

    public function test_capture_plan_stores_project_in_source_metadata(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => 'Plan section from my-app',
                'doc_type' => 'plan',
                'plan_slug' => 'my-plan',
                'project' => 'my-app',
            ],
        ]);

        $response->assertStatus(200);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('my-app', $thought->source_metadata['project'] ?? null);
    }

    public function test_capture_plan_rejects_invalid_doc_type(): void
    {
        [$key] = $this->validKeyAndUser();
        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldReceive('embed')->never();
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => 'Some content',
                'doc_type' => 'invalid',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
    }

    public function test_capture_plan_chunks_when_over_500_words(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $words = array_fill(0, 300, 'word');
        $intro = implode(' ', $words);
        $part2 = implode(' ', array_fill(0, 250, 'more'));
        $content = $intro."\n\n## Part one\n\n".$part2;

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->twice()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => $content,
                'plan_slug' => 'long-doc',
                'doc_type' => 'plan',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.chunked', true);
        $response->assertJsonPath('result.plan_slug', 'long-doc');
        $sectionIds = $response->json('result.section_ids');
        $this->assertIsArray($sectionIds);
        $this->assertCount(2, $sectionIds);

        $root = Thought::find($sectionIds[0]);
        $this->assertNotNull($root);
        $this->assertNull($root->parent_id);
        $this->assertSame('plan', $root->source);
        $this->assertSame(0, $root->source_metadata['section_index'] ?? -1);
        $child = Thought::find($sectionIds[1]);
        $this->assertNotNull($child);
        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(1, $child->source_metadata['section_index'] ?? -1);
        $this->assertSame('Part one', $child->source_metadata['section_title'] ?? null);
        $rootTags = $root->metadata['tags'] ?? [];
        $this->assertContains('plan:long-doc', $rootTags);
        $childTags = $child->metadata['tags'] ?? [];
        $this->assertContains('plan:long-doc', $childTags);
    }

    public function test_capture_plan_no_chunking_opt_out_keeps_single_thought(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $long = implode(' ', array_fill(0, 501, 'word'));
        $content = $long."\n\n## Section\n\nMore text.";

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'capture_plan',
            'params' => [
                'content' => $content,
                'plan_slug' => 'no-chunk-doc',
                'no_chunking' => true,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.id', fn ($id) => is_string($id));
        $this->assertArrayNotHasKey('chunked', $response->json('result'));
        $count = Thought::where('user_id', $user->id)->count();
        $this->assertSame(1, $count);
    }
}

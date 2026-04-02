<?php

namespace Tests\Feature;

use App\Jobs\RunResearchRun;
use App\Jobs\SyncUserJiraActivity;
use App\Models\ResearchRun;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
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
            'methods' => [
                'search_thoughts',
                'browse_recent',
                'thought_stats',
                'capture_thought',
                'capture_plan',
                'capture_meeting',
                'add_meeting',
                'add_meeting_notes',
                'capture_idea',
                'get_ideas',
                'research_idea',
                'capture_video',
            ],
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
        $names = array_column($response->json('result.tools'), 'name');
        $expectedPrefix = [
            'search_thoughts',
            'browse_recent',
            'thought_stats',
            'capture_thought',
            'capture_plan',
            'capture_meeting',
            'add_meeting',
            'add_meeting_notes',
            'capture_idea',
            'get_ideas',
            'research_idea',
            'capture_video',
        ];
        $this->assertSame($expectedPrefix, array_slice($names, 0, count($expectedPrefix)));
        $this->assertContains('capture_video', $names);
        $captureVideo = collect($response->json('result.tools'))->firstWhere('name', 'capture_video');
        $this->assertIsArray($captureVideo);
        $researchNow = data_get($captureVideo, 'inputSchema.properties.research_now.description');
        $this->assertIsString($researchNow);
        $this->assertStringContainsString('queues video research', $researchNow);
        $this->assertStringContainsString('after the transcript reaches a terminal state', $researchNow);
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
        $this->mock(OpenRouterService::class, function ($mock): void {
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

    public function test_capture_plan_with_doc_type_meeting_sets_source_metadata_type_and_tag(): void
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
                'content' => '## Standup — Decisions',
                'doc_type' => 'meeting',
                'plan_slug' => '2026-04-01-standup',
                'section_title' => 'Standup — Decisions',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.doc_type', 'meeting');

        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('meeting', $thought->source);
        $this->assertSame('meeting', $thought->metadata['type'] ?? null);
        $this->assertSame('meeting', $thought->source_metadata['doc_type'] ?? null);
        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('meeting:2026-04-01-standup', $tags);
    }

    public function test_add_meeting_notes_aliases_capture_plan_with_doc_type_meeting(): void
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
            'method' => 'add_meeting_notes',
            'params' => [
                'content' => 'Notes from sync',
                'plan_slug' => 'team-sync-apr-2',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.doc_type', 'meeting');

        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('meeting', $thought->source);
        $this->assertSame('meeting', $thought->metadata['type'] ?? null);
        $tags = $thought->metadata['tags'] ?? [];
        $this->assertContains('meeting:team-sync-apr-2', $tags);
    }

    public function test_capture_meeting_overrides_doc_type_to_meeting(): void
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
            'method' => 'capture_meeting',
            'params' => [
                'content' => 'Should still be meeting',
                'doc_type' => 'plan',
            ],
        ]);

        $response->assertStatus(200);
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('meeting', $thought->source);
        $this->assertSame('meeting', $thought->metadata['type'] ?? null);
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

    public function test_capture_idea_creates_thought_with_idea_metadata(): void
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
            'method' => 'capture_idea',
            'params' => ['content' => 'An idea from MCP'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.id', fn ($id) => is_string($id) && strlen($id) > 0);
        $id = $response->json('result.id');
        $thought = Thought::find($id);
        $this->assertNotNull($thought);
        $this->assertSame($user->id, $thought->user_id);
        $metadata = $thought->metadata ?? [];
        $this->assertSame('idea', $metadata['type'] ?? null);
        $this->assertSame(false, $metadata['completed'] ?? null);
        $this->assertNotEmpty($metadata['logged_date'] ?? null);
    }

    public function test_capture_idea_with_logged_date_stores_date_in_metadata(): void
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
            'method' => 'capture_idea',
            'params' => [
                'content' => 'Idea with specific date',
                'logged_date' => '2025-03-14',
            ],
        ]);

        $response->assertStatus(200);
        $id = $response->json('result.id');
        $thought = Thought::find($id);
        $this->assertNotNull($thought);
        $metadata = $thought->metadata ?? [];
        $this->assertSame('idea', $metadata['type'] ?? null);
        $this->assertSame('2025-03-14', $metadata['logged_date'] ?? null);
    }

    public function test_get_ideas_returns_ideas_array(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_ideas',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.ideas', []);
    }

    public function test_get_ideas_returns_empty_when_user_has_no_incomplete_ideas(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => true],
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_ideas',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.ideas', []);
    }

    public function test_get_ideas_returns_incomplete_ideas_with_expected_shape(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'An incomplete idea to revisit',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'get_ideas',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['result' => ['ideas' => [['id', 'content', 'logged_date', 'created_at']]]]);
        $ideas = $response->json('result.ideas');
        $this->assertNotEmpty($ideas);
        $first = $ideas[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('content', $first);
        $this->assertArrayHasKey('logged_date', $first);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertSame('An incomplete idea to revisit', $first['content']);
        $this->assertSame('2025-03-01', $first['logged_date']);
    }

    public function test_research_idea_with_content_creates_idea_and_queues_research_run(): void
    {
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldNotReceive('researchNote');
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['content' => 'Ship a side project this quarter'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.idea_id', fn ($id) => is_string($id) && strlen($id) > 0);
        $response->assertJsonPath('result.research_run_id', fn ($id) => is_int($id) && $id > 0);
        $response->assertJsonPath('result.research_id', null);

        $ideaId = $response->json('result.idea_id');
        $runId = $response->json('result.research_run_id');

        $idea = Thought::find($ideaId);
        $this->assertNotNull($idea);
        $this->assertSame($user->id, $idea->user_id);
        $this->assertSame('idea', $idea->metadata['type'] ?? null);

        $run = ResearchRun::find($runId);
        $this->assertNotNull($run);
        $this->assertSame($ideaId, $run->idea_thought_id);
        $this->assertSame('queued', $run->status);

        Bus::assertDispatched(RunResearchRun::class, fn (RunResearchRun $job) => $job->researchRunId === $runId);
    }

    public function test_research_idea_with_idea_id_queues_run_returns_run_id(): void
    {
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Build a small SaaS for vehicle analytics',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('researchNote');
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['idea_id' => $idea->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.idea_id', $idea->id);
        $response->assertJsonPath('result.research_run_id', fn ($id) => is_int($id) && $id > 0);
        $response->assertJsonPath('result.research_id', null);

        $runId = $response->json('result.research_run_id');
        $run = ResearchRun::find($runId);
        $this->assertNotNull($run);
        $this->assertSame($idea->id, $run->idea_thought_id);
        $this->assertSame('queued', $run->status);

        Bus::assertDispatched(RunResearchRun::class, fn (RunResearchRun $job) => $job->researchRunId === $runId);
    }

    public function test_research_idea_requires_idea_id_or_content(): void
    {
        $key = $this->validKey();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32602);
        $response->assertJsonPath('error.message', 'At least one of idea_id or content is required.');
    }

    public function test_sync_jira_dispatches_job_and_returns_message(): void
    {
        Config::set('services.jira.enabled', true);
        Bus::fake();
        [$key, $user] = $this->validKeyAndUser();

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'sync_jira',
                'arguments' => ['days' => 7],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.content.0.text', fn ($text) => str_contains($text, 'Jira sync started'));
        Bus::assertDispatched(SyncUserJiraActivity::class);
    }

    public function test_search_thoughts_with_tag_query_returns_tagged_thought_first(): void
    {
        [$key, $user] = $this->validKeyAndUser();
        $embedding = array_fill(0, 1536, 0.01);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Decision about project spec',
            'embedding' => $embedding,
            'metadata' => ['tags' => ['decision:project-spec']],
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($embedding): void {
            $mock->shouldReceive('embed')->once()->with('decision:project-spec')->andReturn($embedding);
        });

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'search_thoughts',
            'params' => ['query' => 'decision:project-spec'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.thoughts.0.id', $thought->id);
    }

    public function test_browse_recent_excludes_hidden_email_thoughts(): void
    {
        [$key, $user] = $this->validKeyAndUser();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'MCP visible row',
            'source' => 'web',
        ]);
        $hidden = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'MCP hidden email row',
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'browse_recent',
            'params' => ['limit' => 10],
        ]);

        $response->assertStatus(200);
        $ids = array_column($response->json('result.thoughts'), 'id');
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_thought_stats_excludes_hidden_email_from_count(): void
    {
        [$key, $user] = $this->validKeyAndUser();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Counted for MCP',
            'source' => 'web',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Not counted hidden',
            'source' => 'email',
            'is_visible_in_stream' => false,
            'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
        ]);

        $response = $this->postJson('/api/mcp?key='.$key, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'thought_stats',
            'params' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('result.count', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function sampleMarkdown(): string
    {
        return <<<'MD'
## Current Focus
- Ship the v2 API by Friday
- Resolve flaky CI pipeline

## Active Priorities
- Migrate to PostgreSQL
- Implement rate limiting

## Recent Changes
- Deployed auth service v1.3
- Fixed N+1 query in dashboard

## Open Questions
- Should we support GraphQL?
- What SLA targets for the new endpoint?

## Risks / Blockers
- Redis cluster upgrade pending

## Next Actions
- Write migration script
- Schedule load test

## Latest Signals
- User signups up 12% this week

## Source Notes
- Weekly standup 2026-05-12
MD;
    }

    #[Test]
    public function it_persists_working_memory_from_markdown(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $version = $service->upsert(
            userId: $user->id,
            scopeType: 'global',
            scopeKey: 'global',
            markdown: $this->sampleMarkdown(),
        );

        $this->assertInstanceOf(WorkingMemoryVersion::class, $version);
        $this->assertEquals('external', $version->build_type);
        $this->assertEquals('external', $version->authoring_status);
        $this->assertEquals(90.0, (float) $version->confidence_score);
        $this->assertStringContainsString('## Current Focus', $version->summary_markdown);

        // Structured sections parsed correctly
        $sections = $version->structured_sections_json;
        $this->assertIsArray($sections);
        $this->assertArrayHasKey('Current Focus', $sections);
        $this->assertArrayHasKey('Active Priorities', $sections);
        $this->assertCount(2, $sections['Current Focus']);
        $this->assertEquals('Ship the v2 API by Friday', $sections['Current Focus'][0]['text']);
        $this->assertArrayHasKey('id', $sections['Current Focus'][0]);
        $this->assertEquals(0, $sections['Current Focus'][0]['importance']);
        $this->assertEquals('direct', $sections['Current Focus'][0]['fallback_mode']);
        $this->assertEquals([], $sections['Current Focus'][0]['citations']);

        // Legacy payload mapped
        $this->assertCount(2, $version->key_concepts_json);
        $this->assertEquals('Migrate to PostgreSQL', $version->key_concepts_json[0]['title']);

        $this->assertCount(2, $version->active_threads_json);
        $this->assertEquals('Deployed auth service v1.3', $version->active_threads_json[0]['title']);

        $this->assertCount(2, $version->open_questions_json);
        $this->assertEquals('Should we support GraphQL?', $version->open_questions_json[0]['question']);

        $this->assertCount(2, $version->next_actions_json);
        $this->assertEquals('Write migration script', $version->next_actions_json[0]['action']);

        // Section references with stream filter URLs
        $sectionRefs = $version->section_references_json;
        $this->assertIsArray($sectionRefs);
        $this->assertArrayHasKey('Current Focus', $sectionRefs);
        $this->assertStringContainsString('/stream?', $sectionRefs['Current Focus'][0]['url']);
        $this->assertEquals('stream_filter', $sectionRefs['Current Focus'][0]['type']);

        // Parent memory record updated
        $memory = WorkingMemory::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'global')
            ->where('scope_key', 'global')
            ->first();

        $this->assertNotNull($memory);
        $this->assertEquals($version->id, $memory->latest_version_id);
        $this->assertEquals('fresh', $memory->freshness_state);
        $this->assertNotNull($memory->last_refreshed_at);
    }

    #[Test]
    public function it_updates_existing_working_memory_with_new_version(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $v1 = $service->upsert(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'my-app',
            markdown: $this->sampleMarkdown(),
        );

        $updatedMarkdown = <<<'MD'
## Current Focus
- Launch beta program

## Active Priorities
- Onboarding flow redesign
MD;

        $v2 = $service->upsert(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'my-app',
            markdown: $updatedMarkdown,
        );

        $this->assertNotEquals($v1->id, $v2->id);
        $this->assertEquals('Launch beta program', $v2->structured_sections_json['Current Focus'][0]['text']);

        $memory = WorkingMemory::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_key', 'my-app')
            ->first();

        $this->assertEquals($v2->id, $memory->latest_version_id);
        $this->assertEquals(2, $memory->versions()->count());
    }

    #[Test]
    public function it_normalizes_scope_key_via_scope_normalizer(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $version = $service->upsert(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'My-App',
            markdown: "## Current Focus\n- Test item",
        );

        $memory = $version->workingMemory;
        $this->assertEquals('my-app', $memory->scope_key);
        $this->assertEquals('project', $memory->scope_type);
    }

    #[Test]
    public function it_returns_external_version_as_canonical_via_forScope(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.\n\n## Recent Changes\n\n- Upgrade scheduled.\n\n## Open Questions\n\n- When is the key available?\n\n## Risks / Blockers\n\n- Blocked on API key.\n\n## Next Actions\n\n- Run staging tests.\n\n## Latest Signals\n\n- Check-in 2026-05-08.\n\n## Source Notes\n\n- Reviewed context.";

        $service->upsert($user->id, 'project', 'dezeen', $markdown);

        $assembler = app(\App\Services\WorkingMemory\WorkingMemoryAssembler::class);
        $payload = $assembler->forScope($user->id, 'project', 'dezeen');

        $this->assertEquals('project', $payload['scope_type']);
        $this->assertEquals('dezeen', $payload['scope_key']);
        $this->assertEquals('external', $payload['baseline_build_type']);
        $this->assertStringContainsString('Ship the fix', $payload['summary_markdown']);
        $this->assertArrayHasKey('Current Focus', $payload['structured_sections']);
    }

    #[Test]
    public function it_rejects_empty_content_with_exception(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->upsert(
            userId: $user->id,
            scopeType: 'global',
            scopeKey: 'global',
            markdown: '',
        );
    }

    #[Test]
    public function it_returns_upserted_memory_via_get_working_memory_mcp(): void
    {
        $user = User::factory()->create();
        $keyValue = 'ideatub_'.str_repeat('u', 32);
        UserMcpKey::query()->create([
            'user_id' => $user->id,
            'key_hash' => UserMcpKey::hashKey($keyValue),
        ]);

        $markdown = <<<'MD'
        ## Current Focus

        - Deliver the comments-page release.

        ## Active Priorities

        - Test Evessio plugin in staging.

        ## Recent Changes

        - WordPress upgrade scheduled for 2026-05-13.

        ## Open Questions

        - When will the API key be available?

        ## Risks / Blockers

        - Evessio blocked on API key access.

        ## Next Actions

        - Get the API key and run staging tests.

        ## Latest Signals

        - Weekly check-in confirmed comments service restored.

        ## Source Notes

        - Reviewed weekly check-in 2026-05-08.
        MD;

        $upsertResponse = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => trim($markdown),
                'source_label' => 'elixirr-sync',
            ],
            'id' => 1,
        ], ['x-ideatub-key' => $keyValue]);
        $upsertResponse->assertOk();
        $this->assertNull($upsertResponse->json('error'));

        $readResponse = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'get_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
            ],
            'id' => 2,
        ], ['x-ideatub-key' => $keyValue]);
        $readResponse->assertOk();

        $result = $readResponse->json('result');

        $this->assertStringContainsString('Deliver the comments-page release', $result['summary_markdown'] ?? '');
        $this->assertEquals('external', $result['baseline_build_type'] ?? '');
        $this->assertEquals('project', $result['scope_type']);
        $this->assertEquals('dezeen', $result['scope_key']);
        $this->assertArrayHasKey('Current Focus', $result['structured_sections'] ?? []);
        $this->assertEquals('fresh', $result['freshness_state']);
    }
}

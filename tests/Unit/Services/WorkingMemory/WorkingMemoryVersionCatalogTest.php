<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryVersionCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryVersionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private WorkingMemoryVersionCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = app(WorkingMemoryVersionCatalog::class);
    }

    public function test_list_returns_newest_first(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);

        $older = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'consolidated',
            'created_at' => now()->subDays(2),
        ]);
        $newer = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'created_at' => now()->subDay(),
        ]);

        $paginator = $this->catalog->listForScope(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
        );

        $this->assertCount(2, $paginator->items());
        $this->assertSame((string) $newer->id, (string) $paginator->items()[0]->id);
        $this->assertSame((string) $older->id, (string) $paginator->items()[1]->id);
    }

    public function test_list_filters_to_canonical_build_types_by_default(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);

        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'external']);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'consolidated']);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'incremental']);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'compaction:meeting']);

        $paginator = $this->catalog->listForScope(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
        );

        $buildTypes = collect($paginator->items())->pluck('build_type')->all();
        $this->assertCount(2, $buildTypes);
        $this->assertEqualsCanonicalizing(['external', 'consolidated'], $buildTypes);
    }

    public function test_list_includes_compactions_when_requested(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);

        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'external']);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'compaction:meeting']);
        WorkingMemoryVersion::factory()->for($memory)->create(['build_type' => 'incremental']);

        $paginator = $this->catalog->listForScope(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
            includeCompactions: true,
        );

        $buildTypes = collect($paginator->items())->pluck('build_type')->all();
        $this->assertCount(2, $buildTypes);
        $this->assertEqualsCanonicalizing(['external', 'compaction:meeting'], $buildTypes);
    }

    public function test_list_returns_empty_paginator_when_memory_missing(): void
    {
        $user = User::factory()->create();

        $paginator = $this->catalog->listForScope(
            $user->id,
            'project',
            '019e0705-5591-73e9-be2e-0fb9c86b269a',
        );

        $this->assertCount(0, $paginator->items());
        $this->assertSame(0, $paginator->total());
    }

    public function test_show_for_user_returns_version_for_owner(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
        ]);

        $found = $this->catalog->showForUser($user->id, (string) $version->id);

        $this->assertTrue($found->is($version));
    }

    public function test_show_for_user_throws_when_version_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $memory = WorkingMemory::factory()->for($owner)->create([
            'scope_type' => 'project',
            'scope_key' => '019e0705-5591-73e9-be2e-0fb9c86b269a',
        ]);
        $version = WorkingMemoryVersion::factory()->for($memory)->create();

        $this->expectException(ModelNotFoundException::class);

        $this->catalog->showForUser($other->id, (string) $version->id);
    }

    public function test_to_list_item_shape(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create();
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'authoring_status' => 'external',
            'confidence_score' => 0.85,
            'citation_coverage' => 0.92,
            'build_diagnostics_json' => ['source_label' => 'elixirr-sync'],
            'created_at' => now(),
        ]);

        $item = $this->catalog->toListItem($version);

        $this->assertSame((string) $version->id, $item['id']);
        $this->assertSame('external', $item['build_type']);
        $this->assertSame('external', $item['authoring_status']);
        $this->assertSame(0.85, $item['confidence_score']);
        $this->assertSame('elixirr-sync', $item['source_label']);
        $this->assertSame(0.92, $item['citation_coverage']);
        $this->assertNotEmpty($item['created_at']);
    }

    public function test_to_detail_payload_includes_version_content(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create();
        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'external',
            'summary_markdown' => '# Summary',
            'structured_sections_json' => ['Goals' => [['text' => 'Ship it']]],
            'section_references_json' => ['Goals' => []],
            'references_json' => [['type' => 'url', 'url' => 'https://example.com', 'label' => 'Example']],
            'key_concepts_json' => [['title' => 'Concept']],
            'active_threads_json' => [['title' => 'Thread']],
            'open_questions_json' => [['question' => 'Why?']],
            'next_actions_json' => [['action' => 'Do it']],
        ]);

        $payload = $this->catalog->toDetailPayload($version);

        $this->assertSame('# Summary', $payload['summary_markdown']);
        $this->assertSame(['Goals' => [['text' => 'Ship it']]], $payload['structured_sections']);
        $this->assertSame(['Goals' => []], $payload['section_references']);
        $this->assertSame([['title' => 'Concept']], $payload['key_concepts']);
        $this->assertArrayHasKey('id', $payload);
    }
}

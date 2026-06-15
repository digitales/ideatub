<?php

namespace Tests\Unit\Services\Attention;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\MeetingActionItemsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingActionItemsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_compaction_returns_action_items_with_compaction_url(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'compaction:meeting',
            'authoring_status' => 'validated',
            'structured_sections_json' => [
                'Action Items' => ['Follow up with client'],
            ],
        ]);

        $items = app(MeetingActionItemsQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame('meeting_action', $items[0]->kind);
        $this->assertStringContainsString('Follow up with client', $items[0]->title);
        $this->assertSame(
            route('memory.compactions.show', [
                'scopeType' => 'global',
                'scopeKey' => 'global',
                'versionId' => $version->id,
            ]),
            $items[0]->href,
        );
    }
}

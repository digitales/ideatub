<?php

namespace Tests\Unit\Services\Inbox\Generators;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Inbox\Generators\MeetingActionInboxGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingActionInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_action_produces_one_inbox_item_per_action(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'build_type' => 'compaction:meeting',
            'structured_sections_json' => [
                'Action Items' => ['Email client summary'],
            ],
        ]);

        $payloads = app(MeetingActionInboxGenerator::class)->generate($user);

        $this->assertCount(1, $payloads);
        $this->assertSame('meeting_action', $payloads[0]['generator_type']);
        $this->assertStringStartsWith('meeting_action:'.$version->id.':', $payloads[0]['dedupe_key']);
        $this->assertStringContainsString('Email client summary', $payloads[0]['body']);
    }
}

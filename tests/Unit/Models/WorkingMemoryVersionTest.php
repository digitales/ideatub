<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingMemoryVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_fields_round_trip_as_arrays(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
            'freshness_state' => 'fresh',
        ]);

        $version = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
            'summary_markdown' => 'Summary',
            'structured_sections_json' => [
                ['title' => 'Overview', 'body' => 'Key points'],
            ],
            'references_json' => [
                ['thought_id' => 'abc-123', 'excerpt' => 'Support text'],
            ],
        ])->refresh();

        $this->assertSame(
            [['title' => 'Overview', 'body' => 'Key points']],
            $version->structured_sections_json
        );
        $this->assertSame(
            [['thought_id' => 'abc-123', 'excerpt' => 'Support text']],
            $version->references_json
        );
    }

    public function test_citation_coverage_casts_to_decimal_two_places(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'global',
            'scope_key' => 'global',
            'freshness_state' => 'fresh',
        ]);

        $version = WorkingMemoryVersion::create([
            'working_memory_id' => $memory->id,
            'build_type' => 'incremental',
            'summary_markdown' => 'Summary',
            'citation_coverage' => 0.8,
        ])->refresh();

        $this->assertSame('0.80', $version->citation_coverage);
    }
}

<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\MemoryInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryInsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthesize_strips_atx_heading_markers_from_capture_titles_for_markdown_lists(): void
    {
        config(['working_memory.insights_model_enabled' => false]);

        $user = User::factory()->create();
        $service = app(MemoryInsightsService::class);

        Thought::factory()->for($user)->create([
            'metadata' => [
                'type' => 'research',
                'tags' => ['news'],
            ],
            'content' => "# 1:1 messaging drives loyalty\n\nMore body.",
        ]);

        $out = $service->synthesizePersistable(
            Thought::query()->where('user_id', $user->id)->get()
        );

        $this->assertStringContainsString('- 1:1 messaging drives loyalty', $out['summary_markdown']);
        $this->assertStringNotContainsString('- # 1:1 messaging', $out['summary_markdown']);

        $firstThread = $out['active_threads'][0] ?? null;
        $this->assertIsArray($firstThread);
        $this->assertSame('1:1 messaging drives loyalty', $firstThread['title'] ?? null);
    }

    public function test_synthesize_strips_heading_markers_from_metadata_title(): void
    {
        config(['working_memory.insights_model_enabled' => false]);

        $user = User::factory()->create();
        $service = app(MemoryInsightsService::class);

        Thought::factory()->for($user)->create([
            'metadata' => [
                'type' => 'research',
                'tags' => ['x'],
                'title' => '## Summary',
            ],
            'content' => 'ignored when title set',
        ]);

        $out = $service->synthesizePersistable(
            Thought::query()->where('user_id', $user->id)->get()
        );

        $this->assertStringContainsString('- Summary', $out['summary_markdown']);
        $this->assertStringNotContainsString('- ## Summary', $out['summary_markdown']);
    }
}

<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryInput;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionVersionWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_a_compaction_version_with_thought_inputs(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $writer = app(CompactionVersionWriter::class);
        $version = $writer->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:meeting',
            summaryMarkdown: "## Summary\nDecided to ship DEZ-2819.",
            structuredSections: [
                'Summary' => [
                    [
                        'id' => 'fixed-id-1',
                        'text' => 'Decided to ship DEZ-2819.',
                        'importance' => 1,
                        'fallback_mode' => 'direct',
                        'citations' => [],
                    ],
                ],
            ],
            references: [],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertSame('compaction:meeting', $version->build_type);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
        $this->assertSame($user->id, $version->workingMemory->user_id);
        $this->assertNotSame($version->id, $version->workingMemory->latest_version_id, 'Compactions must not become latest_version_id');

        $input = WorkingMemoryInput::query()
            ->where('working_memory_version_id', $version->id)
            ->where('thought_id', $thought->id)
            ->first();
        $this->assertNotNull($input);
        $this->assertSame('compaction-source', $input->contribution_type);
    }

    #[Test]
    public function it_rejects_non_compaction_build_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CompactionVersionWriter::class)->write(
            userId: 1,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'consolidated',
            summaryMarkdown: '',
            structuredSections: [],
            references: [],
            sourceThoughtIds: [],
        );
    }
}

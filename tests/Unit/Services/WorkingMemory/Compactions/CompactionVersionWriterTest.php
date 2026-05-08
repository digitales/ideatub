<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Models\WorkingMemoryInput;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    #[Test]
    public function it_logs_warning_but_persists_when_validation_hard_fails_in_observation_mode(): void
    {
        config()->set('working_memory.compaction_validation_enforced', false);
        Log::spy();

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:topic-digest',
            summaryMarkdown: "## Active Priorities\n- Pricing rollback.",
            structuredSections: [
                'Active Priorities' => [
                    ['text' => 'Pricing rollback.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []],
                ],
            ],
            references: [],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertNotNull($version);
        $this->assertSame('compaction:topic-digest', $version->build_type);
        Log::shouldHaveReceived('notice')
            ->withArgs(function ($message, $context = []) use ($user) {
                return str_contains((string) $message, 'CompactionVersionWriter')
                    && str_contains((string) $message, 'observation mode')
                    && ($context['build_type'] ?? null) === 'compaction:topic-digest'
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['scope_type'] ?? null) === 'project'
                    && ($context['scope_key'] ?? null) === 'dezeen'
                    && ($context['enforced'] ?? null) === false
                    && ($context['persistence_aborted'] ?? null) === false
                    && is_array($context['reason_codes'] ?? null)
                    && is_string($context['message'] ?? null) && $context['message'] !== '';
            })
            ->atLeast()
            ->once();
    }

    #[Test]
    public function it_aborts_persistence_when_validation_hard_fails_and_enforcement_is_enabled(): void
    {
        config()->set('working_memory.compaction_validation_enforced', true);
        Log::spy();

        $user = User::factory()->create();

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:topic-digest',
            summaryMarkdown: '## Active Priorities',
            structuredSections: [
                'Active Priorities' => [
                    ['text' => 'Pricing rollback.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []],
                ],
            ],
            references: [],
            sourceThoughtIds: [],
        );

        $this->assertNull($version);
        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:topic-digest')->count());
        $this->assertSame(
            0,
            WorkingMemory::query()
                ->where('user_id', $user->id)
                ->where('scope_type', 'project')
                ->where('scope_key', 'dezeen')
                ->count(),
            'Enforced hard-fail must not open the DB transaction (no WorkingMemory row).'
        );
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['enforced'] ?? null) === true
                && ($context['persistence_aborted'] ?? null) === true
                && ($context['build_type'] ?? null) === 'compaction:topic-digest')
            ->atLeast()
            ->once();
    }

    #[Test]
    public function it_persists_when_build_type_is_outside_the_required_sections_map(): void
    {
        config()->set('working_memory.compaction_validation_enforced', true);

        $user = User::factory()->create();

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:custom-experimental',
            summaryMarkdown: '## Anything goes',
            structuredSections: [
                'Anything' => [
                    ['text' => 'X.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []],
                ],
            ],
            references: [],
            sourceThoughtIds: [],
        );

        $this->assertNotNull($version);
        $this->assertSame('compaction:custom-experimental', $version->build_type);
    }
}

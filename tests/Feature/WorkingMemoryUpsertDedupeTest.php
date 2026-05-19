<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryUpsertDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function sampleMarkdown(): string
    {
        return <<<'MD'
## Current Focus
- Ship the fix

## Active Priorities
- Test staging
MD;
    }

    #[Test]
    public function duplicate_upsert_returns_same_version_without_creating_row(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $projectId = (string) Str::uuid();
        $r1 = $service->upsert($user->id, 'project', $projectId, $this->sampleMarkdown(), 'elixirr-sync');
        $r2 = $service->upsert($user->id, 'project', $projectId, $this->sampleMarkdown(), 'elixirr-sync');

        $this->assertFalse($r1->deduplicated);
        $this->assertTrue($r2->deduplicated);
        $this->assertSame($r1->version->id, $r2->version->id);
        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'external')->count());
    }

    #[Test]
    public function changed_content_creates_new_version_and_supersedes_prior(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $r1 = $service->upsert($user->id, 'project', 'dezeen', $this->sampleMarkdown());
        $r2 = $service->upsert($user->id, 'project', 'dezeen', "## Current Focus\n- Different focus\n\n## Active Priorities\n- Other");

        $this->assertNotSame($r1->version->id, $r2->version->id);
        $r1->version->refresh();
        $this->assertNotNull($r1->version->superseded_at);
        $this->assertSame($r2->version->id, $r1->version->superseded_by_version_id);
    }
}

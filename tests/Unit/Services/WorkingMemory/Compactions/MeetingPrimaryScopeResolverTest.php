<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\Compactions\MeetingPrimaryScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MeetingPrimaryScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Creating meeting Thought rows triggers ThoughtObserver -> SynthesizeMeetingCompactionJob
        // -> OpenRouter. Fake bus + queue so factory creates don't reach the network.
        Bus::fake();
        Queue::fake();
    }

    #[Test]
    public function it_prefers_project_scope_from_source_metadata(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['design-sync']],
            'source_metadata' => ['project' => 'Dezeen'],
        ]);

        [$type, $key] = app(MeetingPrimaryScopeResolver::class)->forThought($meeting);

        $this->assertSame('project', $type);
        $this->assertSame('dezeen', $key);
    }

    #[Test]
    public function it_falls_back_to_first_tag_when_no_project_scope(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['design-sync']],
        ]);

        [$type, $key] = app(MeetingPrimaryScopeResolver::class)->forThought($meeting);

        $this->assertSame('tag', $type);
        $this->assertSame('design-sync', $key);
    }

    #[Test]
    public function it_falls_back_to_global_when_no_project_or_tag_scope(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        [$type, $key] = app(MeetingPrimaryScopeResolver::class)->forThought($meeting);

        $this->assertSame('global', $type);
        $this->assertSame('global', $key);
    }
}

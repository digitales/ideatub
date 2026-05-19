<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryClientRollupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_client_root_includes_child_linked_thought(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00', 'UTC'));

            $user = User::factory()->create();
            $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
            $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();

            $included = Thought::factory()->for($user)->create([
                'content' => 'Child-linked thought for client rollup.',
                'created_at' => Carbon::parse('2026-05-18 10:00:00', 'UTC'),
            ]);
            $child->thoughts()->attach($included->id, ['sort_order' => 1]);

            Thought::factory()->for($user)->create([
                'content' => 'Unrelated thought outside client scope.',
                'source_metadata' => ['project' => 'other-client'],
                'created_at' => Carbon::parse('2026-05-18 11:00:00', 'UTC'),
            ]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'project', (string) $root->id);

            $inputThoughtIds = $version->inputs()->pluck('thought_id');

            $this->assertTrue($inputThoughtIds->containsStrict($included->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function test_client_root_includes_client_tagged_thought(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00', 'UTC'));

            $user = User::factory()->create();
            $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

            $tagged = Thought::factory()->for($user)->create([
                'content' => 'Client-tagged thought for client rollup.',
                'metadata' => ['tags' => ['client:dezeen', 'working-memory']],
                'created_at' => Carbon::parse('2026-05-18 10:00:00', 'UTC'),
            ]);

            Thought::factory()->for($user)->create([
                'content' => 'Sibling subproject metadata should not roll up to client root.',
                'source_metadata' => ['project' => 'dezeen/foo'],
                'created_at' => Carbon::parse('2026-05-18 11:00:00', 'UTC'),
            ]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'project', (string) $root->id);

            $inputThoughtIds = $version->inputs()->pluck('thought_id');

            $this->assertTrue($inputThoughtIds->containsStrict($tagged->id));
            $this->assertSame(1, $inputThoughtIds->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function test_child_scope_excludes_sibling(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00', 'UTC'));

            $user = User::factory()->create();
            $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
            $foo = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
            $bar = Project::factory()->elixirrChild($root, 'bar')->for($user)->create();

            $fooThought = Thought::factory()->for($user)->create([
                'content' => 'Thought linked to foo child project.',
                'created_at' => Carbon::parse('2026-05-18 09:00:00', 'UTC'),
            ]);
            $foo->thoughts()->attach($fooThought->id, ['sort_order' => 1]);

            $siblingThought = Thought::factory()->for($user)->create([
                'content' => 'Thought linked only to sibling bar project.',
                'created_at' => Carbon::parse('2026-05-18 10:00:00', 'UTC'),
            ]);
            $bar->thoughts()->attach($siblingThought->id, ['sort_order' => 1]);

            $version = app(WorkingMemoryBuilderService::class)
                ->buildConsolidated($user->id, 'project', (string) $foo->id);

            $inputThoughtIds = $version->inputs()->pluck('thought_id');

            $this->assertTrue($inputThoughtIds->containsStrict($fooThought->id));
            $this->assertFalse($inputThoughtIds->containsStrict($siblingThought->id));
        } finally {
            Carbon::setTestNow();
        }
    }
}

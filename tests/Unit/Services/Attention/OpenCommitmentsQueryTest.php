<?php

namespace Tests\Unit\Services\Attention;

use App\Models\CommitmentItem;
use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\Attention\OpenCommitmentsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenCommitmentsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_question_with_source_thought_links_to_thought_not_project_memory(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'IdeaTub']);
        $thought = Thought::factory()->for($user)->create();

        CommitmentItem::query()->create([
            'user_id' => $user->id,
            'type' => 'wm_open_question',
            'status' => 'open',
            'title' => 'When to consolidate global?',
            'project_id' => $project->id,
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
            'source_thought_id' => $thought->id,
            'dedupe_key' => 'wm_open_question:test:1',
            'opened_at' => now(),
        ]);

        $items = app(OpenCommitmentsQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame(route('thoughts.show', ['thought' => $thought->id]), $items[0]->href);
        $this->assertSame(['type' => 'thought', 'id' => (string) $thought->id], $items[0]->sourceRef);
    }

    public function test_project_commitment_without_source_thought_links_to_project_memory(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'IdeaTub']);

        CommitmentItem::query()->create([
            'user_id' => $user->id,
            'type' => 'wm_next_action',
            'status' => 'open',
            'title' => 'Ship pulse dashboard',
            'project_id' => $project->id,
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
            'dedupe_key' => 'wm_next_action:test:2',
            'opened_at' => now(),
        ]);

        $items = app(OpenCommitmentsQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame(route('projects.memory.show', $project), $items[0]->href);
    }

    public function test_open_question_without_stored_source_thought_resolves_from_version_citations(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'IdeaTub']);
        $thought = Thought::factory()->for($user)->create();

        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'project',
            'scope_key' => $project->id,
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'structured_sections_json' => [
                'Open Questions' => [[
                    'text' => 'When to consolidate global?',
                    'citations' => [[
                        'type' => 'thought',
                        'url' => route('thoughts.show', ['thought' => $thought->id]),
                        'label' => 'Planning note',
                        'thought_id' => (string) $thought->id,
                    ]],
                ]],
            ],
        ]);

        CommitmentItem::query()->create([
            'user_id' => $user->id,
            'type' => 'wm_open_question',
            'status' => 'open',
            'title' => 'When to consolidate global?',
            'project_id' => $project->id,
            'scope_type' => 'project',
            'scope_key' => (string) $project->id,
            'source_version_id' => $version->id,
            'dedupe_key' => 'wm_open_question:test:3',
            'opened_at' => now(),
        ]);

        $items = app(OpenCommitmentsQuery::class)->forUser($user->id);

        $this->assertCount(1, $items);
        $this->assertSame(route('thoughts.show', ['thought' => $thought->id]), $items[0]->href);
    }
}

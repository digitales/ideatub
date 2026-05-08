<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Support\TagSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoryScopesIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_redirects_to_login(): void
    {
        config(['features.working_memory_ui' => true]);

        $response = $this->get(route('memory.scopes.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_flag_off_returns_404(): void
    {
        config(['features.working_memory_ui' => false]);
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertNotFound();
    }

    public function test_empty_state_shows_copy(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertOk();
        $response->assertSee('All memories', false);
        $response->assertSee('Open global working memory', false);
        $response->assertSee(route('memory.show'), false);
    }

    public function test_sections_ordered_and_sorted(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();
        $olderProject = Project::factory()->for($user)->create(['title' => 'Project Alpha Older']);
        $newerProject = Project::factory()->for($user)->create(['title' => 'Project Beta Newer']);

        $this->createMemory($user, [
            'scope_type' => 'global',
            'scope_key' => 'global',
            'last_refreshed_at' => now()->subDays(4),
        ]);
        $this->createMemory($user, [
            'scope_type' => 'insights',
            'scope_key' => 'global',
            'last_refreshed_at' => now()->subDays(3),
        ]);
        $this->createMemory($user, [
            'scope_type' => 'project',
            'scope_key' => $olderProject->id,
            'last_refreshed_at' => now()->subDays(2),
        ]);
        $this->createMemory($user, [
            'scope_type' => 'project',
            'scope_key' => $newerProject->id,
            'last_refreshed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));
        $html = (string) $response->getContent();

        $response->assertOk();
        $this->assertSectionHeadingsInOrder($html, ['Global', 'Insights', 'Projects']);
        $response->assertSeeInOrder(['Project Beta Newer', 'Project Alpha Older']);
    }

    public function test_updating_badge(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();

        $this->createMemory($user, [
            'scope_type' => 'global',
            'scope_key' => 'global',
            'build_started_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertOk();
        $response->assertSee('Updating', false);
    }

    public function test_fallback_badge(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();

        $this->createMemory($user, [
            'scope_type' => 'global',
            'scope_key' => 'global',
            'build_started_at' => null,
        ], 'fallback');

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertOk();
        $response->assertSee('Fallback', false);
    }

    public function test_orphan_project_links_to_projects_index(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();

        $this->createMemory($user, [
            'scope_type' => 'project',
            'scope_key' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertOk();
        $response->assertSee('Unavailable project', false);
        $response->assertSee(route('projects.index'), false);
    }

    public function test_tag_section_shows_canonical_label_and_bounded_thought_queries(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = $this->createUser();

        foreach (range(1, 12) as $i) {
            Thought::factory()->for($user)->create([
                'metadata' => ['tags' => ["bulk-tag-$i"]],
            ]);
        }

        Thought::factory()->for($user)->create([
            'metadata' => ['tags' => ['Display Canonical']],
        ]);

        $slug = TagSlug::from('Display Canonical');

        $this->createMemory($user, [
            'scope_type' => 'tag',
            'scope_key' => $slug,
        ]);

        foreach (['alpha_tag', 'beta_tag'] as $key) {
            $this->createMemory($user, [
                'scope_type' => 'tag',
                'scope_key' => $key,
            ]);
        }

        $thoughtSelects = 0;
        DB::listen(function ($query) use (&$thoughtSelects): void {
            if (! preg_match('/^\s*select/i', $query->sql)) {
                return;
            }
            if (! preg_match('/\bthoughts\b/i', $query->sql)) {
                return;
            }
            $thoughtSelects++;
        });

        $response = $this->actingAs($user)->get(route('memory.scopes.index'));

        $response->assertOk();
        $response->assertSee('Display Canonical', false);
        $this->assertLessThanOrEqual(3, $thoughtSelects);
    }

    private function createUser(): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMemory(User $user, array $attributes, string $authoringStatus = 'validated'): WorkingMemory
    {
        $memory = WorkingMemory::factory()
            ->for($user)
            ->create($attributes + [
                'freshness_state' => 'fresh',
                'last_refreshed_at' => now(),
            ]);

        $version = WorkingMemoryVersion::factory()
            ->for($memory)
            ->create([
                'authoring_status' => $authoringStatus,
                'summary_markdown' => '# Working memory',
            ]);

        $memory->update(['latest_version_id' => $version->id]);

        return $memory->fresh();
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertSectionHeadingsInOrder(string $html, array $expected): void
    {
        preg_match_all('/<h2\b[^>]*>\s*([^<]+)\s*<\/h2>/i', $html, $matches);

        $this->assertSame($expected, array_values(array_intersect($matches[1] ?? [], $expected)));
    }
}

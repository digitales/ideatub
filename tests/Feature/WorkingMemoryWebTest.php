<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WorkingMemoryWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_get_memory_redirects_to_login(): void
    {
        config(['features.working_memory_ui' => true]);

        $response = $this->get(route('memory.show'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_flag_off_returns_404(): void
    {
        config(['features.working_memory_ui' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertNotFound();
    }

    public function test_authenticated_flag_on_returns_200_and_shows_title(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Details', false);
    }

    public function test_global_memory_page_shows_refresh_button_with_global_refresh_action(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('Refresh working memory', false);
        $response->assertSee('action="'.route('working-memory.refresh.global').'"', false);
    }

    public function test_project_memory_other_user_returns_403(): void
    {
        config(['features.working_memory_ui' => true]);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->get(route('projects.memory.show', $project));

        $response->assertForbidden();
    }

    public function test_project_memory_owner_with_flag_returns_200(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'Alpha Research']);

        $response = $this->actingAs($user)->get(route('projects.memory.show', $project));

        $response->assertOk();
        $response->assertSee('Working memory', false);
        $response->assertSee('Alpha Research', false);
        $response->assertSee('Details', false);
    }

    public function test_project_memory_page_shows_refresh_button_with_project_refresh_action(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['title' => 'Alpha Research']);

        $response = $this->actingAs($user)->get(route('projects.memory.show', $project));

        $response->assertOk();
        $response->assertSee('Refresh working memory', false);
        $response->assertSee('action="'.route('working-memory.refresh.project', $project).'"', false);
    }

    public function test_working_memory_shows_details_and_recent_updates_when_overlay_deltas_exist(): void
    {
        $this->enableWorkingMemoryUi();
        $user = $this->createUserWithConsolidatedMemory('Baseline memory context for canonical summary.');
        $this->appendIncrementalOverlay($user, 'Incremental overlay signal for recent updates card.');

        $response = $this->actingAs($user)->get(route('memory.show'));
        $html = (string) $response->getContent();

        $response->assertOk();
        $response->assertSee('Details', false);
        $response->assertSeeText('Incremental overlay signal for recent updates card.');
        $this->assertGreaterThanOrEqual(1, $this->recentUpdatesHeadingCount($html));
    }

    public function test_working_memory_hides_recent_updates_block_when_overlay_deltas_are_empty(): void
    {
        $this->enableWorkingMemoryUi();
        $user = $this->createUserWithConsolidatedMemory('Only consolidated context for empty overlay check.');

        $response = $this->actingAs($user)->get(route('memory.show'));
        $html = (string) $response->getContent();

        $response->assertOk();
        $response->assertSee('Details', false);
        $this->assertSame(0, $this->recentUpdatesHeadingCount($html));
    }

    public function test_working_memory_does_not_render_legacy_mobile_drawer_trigger_affordance(): void
    {
        $this->enableWorkingMemoryUi();
        $user = $this->createUserWithConsolidatedMemory('Baseline detail for drawer regression guard.');
        $this->appendIncrementalOverlay($user, 'Overlay detail to surface recent updates region.');

        $response = $this->actingAs($user)->get(route('memory.show'));
        $html = (string) $response->getContent();
        $legacyDrawerMatches = $this->legacyDrawerTriggerMatches($html);

        $response->assertOk();
        $this->assertCount(0, $legacyDrawerMatches, 'Matched legacy drawer fragments: '.implode(' || ', $legacyDrawerMatches));
    }

    public function test_working_memory_renders_structured_sections_and_reference_chips_when_available(): void
    {
        config([
            'features.working_memory_ui' => true,
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);

        $user = $this->createUserWithConsolidatedMemory('Structured section evidence for working memory page.');

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('Current Focus', false);
        $response->assertSee('Active Priorities', false);
        $response->assertSee('/thoughts/', false);
    }

    public function test_working_memory_prefers_markdown_fallback_when_authoring_status_is_fallback(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'authoring_status' => 'fallback',
                    'summary_markdown' => "## Markdown fallback heading\n\n- Fallback summary bullet",
                    'structured_sections' => [
                        'Current Focus' => ['Structured section bullet should not render'],
                    ],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('Markdown fallback heading', false);
        $response->assertSee('Fallback summary bullet', false);
        $response->assertDontSee('Current Focus', false);
        $response->assertDontSee('Structured section bullet should not render', false);
    }

    public function test_working_memory_skips_unsafe_reference_urls(): void
    {
        config(['features.working_memory_ui' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'structured_sections' => [
                        'Current Focus' => ['Improve reference safety checks'],
                    ],
                    'references' => [
                        ['url' => 'https://example.com/reference', 'label' => 'Safe External'],
                        ['url' => '/thoughts/123', 'label' => 'Safe Internal'],
                        ['url' => 'javascript:alert(1)', 'label' => 'Unsafe Script'],
                        ['url' => 'data:text/html;base64,PHNjcmlwdA==', 'label' => 'Unsafe Data'],
                    ],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.show'));

        $response->assertOk();
        $response->assertSee('href="https://example.com/reference"', false);
        $response->assertSee('href="/thoughts/123"', false);
        $response->assertDontSee('href="javascript:alert(1)"', false);
        $response->assertDontSee('href="data:text/html;base64,PHNjcmlwdA=="', false);
        $response->assertDontSee('Unsafe Script');
        $response->assertDontSee('Unsafe Data');
    }

    private function enableWorkingMemoryUi(): void
    {
        config(['features.working_memory_ui' => true]);
    }

    private function createUserWithConsolidatedMemory(string $content): User
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'UTC'));

        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => $content,
            'metadata' => ['tags' => ['baseline']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

        return $user;
    }

    private function appendIncrementalOverlay(User $user, string $content): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 10:00:00', 'UTC'));

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => $content,
            'metadata' => ['tags' => ['overlay']],
        ]);

        app(WorkingMemoryBuilderService::class)->buildIncremental($user->id, 'global', 'global');
    }

    private function recentUpdatesHeadingCount(string $html): int
    {
        preg_match_all('/<h[1-6][^>]*>\s*Recent updates\s*<\/h[1-6]>/i', $html, $matches);

        return count($matches[0] ?? []);
    }

    /**
     * @return array<int, string>
     */
    private function legacyDrawerTriggerMatches(string $html): array
    {
        preg_match_all(
            '/<button\b[^>]*@click="drawerOpen = true"[^>]*>\s*Recent updates\s*<\/button>/i',
            $html,
            $matches
        );

        return $matches[0] ?? [];
    }
}

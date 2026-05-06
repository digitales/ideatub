<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MemoryInsightsWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_get_memory_insights_redirects_to_login(): void
    {
        config(['features.working_memory_insights' => true]);

        $response = $this->get(route('memory.insights'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_insights_flag_off_returns_404(): void
    {
        config(['features.working_memory_insights' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertNotFound();
    }

    public function test_authenticated_insights_flag_on_returns_200(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        Thought::factory()
            ->for($user)
            ->create([
                'metadata' => [
                    'type' => 'research',
                    'tags' => ['strategy', 'markets'],
                ],
                'content' => 'A longer research capture title that should appear in notable captures section for the user.',
            ]);

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('Memory insights', false);
        $response->assertSee('Themes', false);
        $response->assertSee('Notable captures', false);
        $response->assertSee('strategy', false);
    }

    public function test_insights_renders_structured_sections_and_citation_links_when_available(): void
    {
        config([
            'features.working_memory_insights' => true,
            'features.working_memory_ai_authored' => true,
            'working_memory.authoring_enabled' => true,
            'working_memory.citation_min_coverage' => 0.75,
        ]);
        $user = User::factory()->create();

        Thought::factory()
            ->for($user)
            ->create([
                'metadata' => [
                    'type' => 'research',
                    'tags' => ['insights', 'signals'],
                ],
                'content' => 'Research signal for structured insights rendering.',
            ]);

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('Current Focus', false);
        $response->assertSee('Latest Signals', false);
        $response->assertSee('/thoughts/', false);
    }

    public function test_insights_prefers_markdown_fallback_when_authoring_status_is_fallback(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'authoring_status' => 'fallback',
                    'summary_markdown' => "## Insights fallback heading\n\n- Insights fallback bullet",
                    'structured_sections' => [
                        'Current Focus' => ['Insights structured bullet should not render'],
                    ],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('Insights fallback heading', false);
        $response->assertSee('Insights fallback bullet', false);
        $response->assertDontSee('Current Focus', false);
        $response->assertDontSee('Insights structured bullet should not render', false);
    }

    public function test_insights_skips_unsafe_reference_urls(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'structured_sections' => [
                        'Current Focus' => ['Track latest insight references safely'],
                    ],
                    'references' => [
                        ['url' => 'http://example.com/insight', 'label' => 'Safe External'],
                        ['url' => '/thoughts/456', 'label' => 'Safe Internal'],
                        ['url' => 'javascript:alert(1)', 'label' => 'Unsafe Script'],
                        ['url' => 'data:text/html;base64,PHNjcmlwdA==', 'label' => 'Unsafe Data'],
                    ],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('href="http://example.com/insight"', false);
        $response->assertSee('href="/thoughts/456"', false);
        $response->assertDontSee('href="javascript:alert(1)"', false);
        $response->assertDontSee('href="data:text/html;base64,PHNjcmlwdA=="', false);
        $response->assertDontSee('Unsafe Script');
        $response->assertDontSee('Unsafe Data');
    }

    public function test_insights_renders_item_level_citations_and_source_bundle_badge(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'authoring_status' => 'validated',
                    'structured_sections' => [
                        'Latest Signals' => [
                            [
                                'text' => 'Insight bullet with item citations rendered inline.',
                                'fallback_mode' => 'direct',
                                'citations' => [
                                    ['url' => '/thoughts/888', 'label' => 'Signal thought', 'type' => 'thought'],
                                    ['url' => 'https://example.net/paper', 'label' => 'Research PDF', 'type' => 'source'],
                                    ['url' => 'javascript:void(0)', 'label' => 'Blocked citation', 'type' => 'source'],
                                ],
                            ],
                            [
                                'text' => 'Bundled provenance for this insight line.',
                                'fallback_mode' => 'section_bundle',
                                'citations' => [
                                    ['url' => '/thoughts/insights-bundle', 'label' => 'Section sources', 'type' => 'bundle'],
                                ],
                            ],
                        ],
                    ],
                    'references' => [],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('Insight bullet with item citations rendered inline.', false);
        $response->assertSee('href="/thoughts/888"', false);
        $response->assertSee('Signal thought', false);
        $response->assertSee('href="https://example.net/paper"', false);
        $response->assertSee('Research PDF', false);
        $response->assertDontSee('href="javascript:void(0)"', false);
        $response->assertDontSee('Blocked citation', false);

        $response->assertSee('Bundled provenance for this insight line.', false);
        $response->assertSee('Source bundle', false);
        $response->assertSee('href="/thoughts/insights-bundle"', false);
        $response->assertSee('Section sources', false);
    }

    public function test_insights_renders_repo_relative_citation_labels_without_unsafe_hrefs(): void
    {
        config(['features.working_memory_insights' => true]);
        $user = User::factory()->create();

        $this->mock(WorkingMemoryAssembler::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forScope')
                ->once()
                ->andReturn([
                    'authoring_status' => 'validated',
                    'structured_sections' => [
                        'Current Focus' => [
                            [
                                'text' => 'Repo-relative citation labels remain readable without unsafe anchors.',
                                'fallback_mode' => 'direct',
                                'citations' => [
                                    [
                                        'url' => 'docs/research-to-decision/sources/paper.md',
                                        'label' => 'paper.md',
                                        'type' => 'source',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'references' => [],
                ]);
        });

        $response = $this->actingAs($user)->get(route('memory.insights'));

        $response->assertOk();
        $response->assertSee('paper.md', false);
        $response->assertDontSee('href="docs/', false);
    }
}

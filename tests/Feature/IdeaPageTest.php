<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IdeaPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_idea_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('What are you thinking?');
        $response->assertSee('Store thought');
        $response->assertSee('Help');
        $response->assertSee('Find a memory');
    }

    public function test_idea_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_idea_page_shows_stored_thoughts(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'This is a test thought about semantic search',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('This is a test thought about semantic search');
        $response->assertSee('Recent thoughts');
    }

    public function test_idea_page_renders_edit_affordance_and_content_update_route(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Owner thought for edit hook',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('Edit');
        $response->assertSee(route('ideas.update-content', $thought), false);
        $response->assertSee('thought-edit-requested', false);
    }

    public function test_reply_context_renders_inline_editor_escape_boundary(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Parent thought for reply context',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index', ['parent_id' => $thought->id]));

        $response->assertOk();
        $response->assertSee('x-on:keydown.escape.stop.prevent="cancelEdit()"', false);
    }

    public function test_idea_page_thought_cards_link_to_detail_page(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Linked thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee(route('thoughts.show', $thought), false);
    }

    public function test_idea_page_recent_thoughts_exclude_jira(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal recent thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira synced ticket',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('Normal recent thought');
        $response->assertDontSee('Jira synced ticket');
    }

    public function test_idea_page_recent_thoughts_exclude_case_insensitive_jira_and_research(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal recent thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Uppercase Jira synced ticket',
            'parent_id' => null,
            'source' => 'JIRA',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Mixed case research note',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'Research'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('Normal recent thought');
        $response->assertDontSee('Uppercase Jira synced ticket');
        $response->assertDontSee('Mixed case research note');
    }

    public function test_idea_page_shows_search_results(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('pgvector')->andReturn($fakeEmbedding);
        });

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'pgvector is great for embeddings',
            'embedding' => $fakeEmbedding,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pgvector']));

        $response->assertStatus(200);
        $response->assertSee('pgvector is great for embeddings');
    }

    public function test_web_created_thought_has_source_web(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('thoughts.store'), [
            'content' => 'A thought from the web',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.index'));
        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame('web', $thought->source);
    }

    public function test_stored_thought_tags_are_normalized_to_lowercase(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => ['Jira', 'Web Development']]);
        });

        $this->actingAs($user)->post(route('thoughts.store'), [
            'content' => 'A thought with mixed-case tags',
            '_token' => csrf_token(),
        ]);

        $thought = Thought::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($thought);
        $this->assertSame(['jira', 'web development'], $thought->metadata['tags'] ?? null);
    }

    public function test_web_capture_chunks_when_over_500_words(): void
    {
        $user = User::factory()->create();
        $intro = implode(' ', array_fill(0, 300, 'word'));
        $part2 = implode(' ', array_fill(0, 250, 'more'));
        $content = $intro."\n\n## Part one\n\n".$part2;

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->twice()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('thoughts.store'), [
            'content' => $content,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.index'));
        $response->assertSessionHas('success', 'Saved as 2 sections.');
        $root = Thought::where('user_id', $user->id)->whereNull('parent_id')->latest()->first();
        $this->assertNotNull($root);
        $this->assertSame('web', $root->source);
        $this->assertSame(0, $root->source_metadata['section_index'] ?? -1);
        $child = Thought::where('user_id', $user->id)->where('parent_id', $root->id)->first();
        $this->assertNotNull($child);
        $this->assertSame('Part one', $child->source_metadata['section_title'] ?? null);
        $this->assertSame(2, Thought::where('user_id', $user->id)->count());
    }

    public function test_web_capture_no_chunking_keeps_single_thought(): void
    {
        $user = User::factory()->create();
        $long = implode(' ', array_fill(0, 501, 'word'));
        $content = $long."\n\n## Section\n\nMore text.";

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('thoughts.store'), [
            'content' => $content,
            'no_chunking' => '1',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.index'));
        $this->assertSame(1, Thought::where('user_id', $user->id)->count());
    }

    public function test_help_page_includes_example_prompts(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('help'));

        $response->assertStatus(200);
        $response->assertSee('Help');
        $response->assertSee('Example prompts');
        $response->assertSee('Memory Migration');
        $response->assertSee('Second Brain Migration');
        $response->assertSee('Quick Capture Templates');
        $response->assertSee('The Weekly Review');
        $response->assertSee('Decision: [what was decided]');
        $response->assertSee('promptkit.natebjones.com');
    }

    public function test_example_prompts_route_redirects_to_help(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('example-prompts'));

        $response->assertRedirect('/help#example-prompts');
    }

    public function test_authenticated_layout_includes_stable_navigation_test_hooks(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        foreach ([
            'primary-nav',
            'types-menu-trigger',
            'types-menu-list',
            'mobile-nav-trigger',
            'mobile-nav-panel',
            'account-menu-inbox-link',
        ] as $id) {
            $response->assertSee('data-testid="'.$id.'"', false);
        }
    }

    public function test_primary_nav_cluster_contains_only_focused_destinations(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();

        $xpath = $this->xpathFromResponse($response);
        $primary = $xpath->query('//*[@data-testid="primary-nav"]')->item(0);
        $this->assertNotNull($primary);
        $text = $primary->textContent ?? '';
        $this->assertStringContainsString('Ideas', $text);
        $this->assertStringContainsString('Stream', $text);
        $this->assertStringContainsString('Types', $text);
        $this->assertStringContainsString('Help', $text);
        $this->assertStringContainsString('Keyboard shortcuts', $text);
        $this->assertStringNotContainsString('Inbox', $text);
        $this->assertStringNotContainsString('Ideas to revisit', $text);

        // No top-level Jira nav item; Jira may only appear inside the Types dropdown.
        $jiraOutsideTypes = $xpath->query(
            './/a[normalize-space(.)="Jira" and not(ancestor::*[@data-testid="types-menu-list"])]',
            $primary
        );
        $this->assertSame(0, $jiraOutsideTypes->length);

        $inboxLinks = $xpath->query('.//a[contains(@href, "inbox")]', $primary);
        $this->assertSame(0, $inboxLinks->length);
    }

    public function test_types_menu_lists_type_collections_in_order(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();

        $xpath = $this->xpathFromResponse($response);
        $links = $xpath->query('//*[@data-testid="types-menu-list"]//a[@role="menuitem"]');
        $labels = [];
        foreach ($links as $link) {
            $labels[] = trim($link->textContent);
        }

        $this->assertSame(['Jira', 'Emails', 'Research', 'Plans'], $labels);
    }

    public function test_types_menu_omits_jira_when_jira_is_disabled(): void
    {
        config(['services.jira.enabled' => false]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();

        $xpath = $this->xpathFromResponse($response);
        $links = $xpath->query('//*[@data-testid="types-menu-list"]//a[@role="menuitem"]');
        $labels = [];
        foreach ($links as $link) {
            $labels[] = trim($link->textContent);
        }

        $this->assertSame(['Emails', 'Research', 'Plans'], $labels);
    }

    public function test_compact_overflow_navigation_contains_reachable_entries(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $xpath = $this->xpathFromResponse($response);
        $panel = $xpath->query('//*[@data-testid="mobile-nav-panel"]')->item(0);

        $this->assertNotNull($panel);
        $this->assertSame(1, $xpath->query('//*[@data-testid="mobile-nav-trigger"]')->length);

        $labels = [];
        foreach ($xpath->query('.//a | .//button', $panel) as $item) {
            $labels[] = trim($item->textContent);
        }

        $this->assertContains('Ideas', $labels);
        $this->assertContains('Stream', $labels);
        $this->assertContains('Help', $labels);
        $this->assertContains('Keyboard shortcuts', $labels);
        $this->assertContains('Jira', $labels);
        $this->assertContains('Emails', $labels);
        $this->assertContains('Research', $labels);
        $this->assertContains('Plans', $labels);

        $typeLabel = $xpath->query('.//p[normalize-space(.)="Types"]', $panel);
        $this->assertSame(1, $typeLabel->length);
    }

    public function test_mobile_nav_lists_type_collections_in_order(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();

        $this->assertSame(
            ['Jira', 'Emails', 'Research', 'Plans'],
            $this->mobileTypeLabelsFromResponse($response)
        );
    }

    public function test_mobile_nav_omits_jira_when_jira_is_disabled(): void
    {
        config(['services.jira.enabled' => false]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();

        $this->assertSame(
            ['Emails', 'Research', 'Plans'],
            $this->mobileTypeLabelsFromResponse($response)
        );
    }

    private function xpathFromResponse(TestResponse $response): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($dom);
    }

    private function mobileTypeLabelsFromResponse(TestResponse $response): array
    {
        $xpath = $this->xpathFromResponse($response);
        $panel = $xpath->query('//*[@data-testid="mobile-nav-panel"]')->item(0);
        $this->assertNotNull($panel);

        $labels = [];
        foreach ($xpath->query('.//a[contains(@href, "/stream/")]', $panel) as $link) {
            $labels[] = trim($link->textContent);
        }

        return $labels;
    }
}

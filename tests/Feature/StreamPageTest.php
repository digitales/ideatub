<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StreamPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.jira.enabled' => true]);
    }

    public function test_stream_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Stream', false);
    }

    public function test_stream_page_shows_type_navigation_with_all_thoughts_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $this->assertStreamTypeNav($response, route('idea.stream'));
    }

    public function test_typed_stream_page_marks_active_type_navigation_option(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream.jira'));

        $response->assertOk();
        $this->assertStreamTypeNav($response, route('idea.stream.jira'));
    }

    public function test_stream_page_renders_edit_affordance_and_content_update_route(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream thought for edit hook',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('Edit');
        $response->assertSee(route('ideas.update-content', $thought), false);
        $response->assertSee('thought-edit-requested', false);
    }

    public function test_stream_page_renders_a_single_polling_refetch_path(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream thought for polling script',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'function refetchStream()'));
    }

    public function test_stream_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.stream'));

        $response->assertRedirect(route('login'));
    }

    public function test_stream_tag_filter_shows_only_matching_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Work thought',
            'metadata' => ['tags' => ['work']],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Personal thought',
            'metadata' => ['tags' => ['personal']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

        $response->assertStatus(200);
        $response->assertSee('Work thought');
        $response->assertDontSee('Personal thought');
    }

    public function test_stream_thought_cards_link_to_detail_page(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream linked thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee(route('thoughts.show', $thought), false);
    }

    public function test_stream_comment_preview_links_to_detail_page(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Stream root thought',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $thought->id,
            'content' => 'Stream comment preview',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), route('thoughts.show', $thought)));
    }

    public function test_stream_tag_filter_empty_shows_message(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Only work',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'nonexistent']));

        $response->assertStatus(200);
        $response->assertSee('No thoughts with tag');
        $response->assertSee('All thoughts', false);
    }

    public function test_stream_tag_page_hides_type_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

        $response->assertOk();
        $response->assertDontSee('data-testid="stream-type-nav"', false);
    }

    public function test_stream_tag_slug_is_url_safe(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Web dev thought',
            'metadata' => ['tags' => ['web development']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'web_development']));

        $response->assertStatus(200);
        $response->assertSee('Web dev thought');
        $response->assertSee('web_development', false);
        $response->assertDontSee('web%20development', false);
    }

    public function test_stream_link_in_nav(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertStatus(200);
        $response->assertSee('Stream', false);
        $response->assertSee(route('idea.stream'), false);
    }

    public function test_stream_tag_filter_includes_parent_when_children_have_tag(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Doc intro',
            'metadata' => ['tags' => ['work']],
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'Section two content here',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'work']));

        $response->assertStatus(200);
        $response->assertSee('Doc intro');
        $response->assertSee('Section two content here');
    }

    public function test_stream_tag_filter_shows_parent_with_tag_only_in_children(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Document root',
            'metadata' => [], // root has no tag; only sections do
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'Section with doc tag',
            'metadata' => ['tags' => ['doc sections']],
        ]);

        // Slug for "doc sections" is doc_sections
        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'doc_sections']));

        $response->assertStatus(200);
        $response->assertSee('Document root');
        $response->assertSee('Section with doc tag');
    }

    public function test_stream_tag_view_shows_full_section_content(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Root',
            'metadata' => ['tags' => ['full doc']],
            'parent_id' => null,
        ]);
        $longSection = str_repeat('Paragraph content. ', 50);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => $longSection,
            'metadata' => ['tags' => ['full doc']],
        ]);

        // Slug for "full doc" is full_doc
        $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'full_doc']));

        $response->assertStatus(200);
        $response->assertSee($longSection, false);
    }

    public function test_stream_excludes_jira_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira ticket PROJ-123',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Normal thought');
        $response->assertDontSee('Jira ticket PROJ-123');
    }

    public function test_stream_excludes_case_insensitive_jira_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Uppercase Jira ticket',
            'parent_id' => null,
            'source' => 'JIRA',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Normal thought');
        $response->assertDontSee('Uppercase Jira ticket');
    }

    public function test_stream_includes_research_thoughts_unlike_home_recent_feed(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Research doc root',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'Research'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertStatus(200);
        $response->assertSee('Research doc root');
    }

    public function test_jira_stream_shows_only_jira_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Normal thought',
            'parent_id' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira ticket PROJ-123',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.jira'));

        $response->assertStatus(200);
        $response->assertSee('Jira');
        $response->assertSee('Jira ticket PROJ-123');
        $response->assertDontSee('Normal thought');
    }

    public function test_jira_stream_orders_by_jira_activity_time_descending(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Older Jira event',
            'parent_id' => null,
            'source' => 'jira',
            'source_metadata' => [
                'jira_updated_at' => '2026-03-10T12:00:00.000+0000',
            ],
            'created_at' => now()->subMinutes(1),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Newer Jira event',
            'parent_id' => null,
            'source' => 'jira',
            'source_metadata' => [
                'jira_updated_at' => '2026-03-20T12:00:00.000+0000',
            ],
            'created_at' => now()->subDays(7),
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.jira'));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['Newer Jira event', 'Older Jira event']);
        $response->assertSee('data-stream-since="2026-03-20T12:00:00.000+0000"', false);
    }

    private function assertStreamTypeNav(TestResponse $response, string $activeHref): void
    {
        $response->assertSee('data-testid="stream-type-nav"', false);

        $xpath = $this->xpathFromResponse($response);
        $navNodes = $xpath->query('//*[@data-testid="stream-type-nav"]');
        $this->assertSame(1, $navNodes->length);

        $nav = $navNodes->item(0);
        $expectedLinks = [
            'All thoughts' => route('idea.stream'),
            'Jira' => route('idea.stream.jira'),
            'Emails' => route('idea.stream.emails'),
            'Research' => route('idea.stream.research'),
            'Plans' => route('idea.stream.plans'),
        ];

        foreach ($expectedLinks as $label => $href) {
            $link = $xpath->query(".//a[@href='{$href}' and normalize-space(.)='{$label}']", $nav)->item(0);

            $this->assertNotNull($link, sprintf('Missing stream type nav link for [%s].', $label));
            $this->assertSame($href === $activeHref ? 'page' : '', $link->getAttribute('aria-current'));
        }
    }

    private function xpathFromResponse(TestResponse $response): DOMXPath
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($dom);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Support\ThoughtTypeNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtTypePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_jira_type_page_shows_only_jira_thoughts(): void
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

        $response->assertOk();
        $response->assertSee('Jira', false);
        $response->assertSee('Jira ticket PROJ-123');
        $response->assertDontSee('Normal thought');
    }

    public function test_jira_type_page_includes_case_insensitive_source_values(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Uppercase Jira ticket',
            'parent_id' => null,
            'source' => 'JIRA',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.jira'));

        $response->assertOk();
        $response->assertSee('Uppercase Jira ticket');
    }

    public function test_emails_type_page_shows_only_email_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'From inbox',
            'parent_id' => null,
            'source' => 'email',
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Web capture',
            'parent_id' => null,
            'source' => 'web',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.emails'));

        $response->assertOk();
        $response->assertSee('Emails', false);
        $response->assertSee('From inbox');
        $response->assertDontSee('Web capture');
    }

    public function test_emails_type_page_includes_email_alias_source_values(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Aliased email import',
            'parent_id' => null,
            'source' => 'EmailS',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.emails'));

        $response->assertOk();
        $response->assertSee('Aliased email import');
    }

    public function test_research_type_page_shows_only_research_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Research doc root',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Plain note',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.research'));

        $response->assertOk();
        $response->assertSee('Research', false);
        $response->assertSee('Research doc root');
        $response->assertDontSee('Plain note');
    }

    public function test_research_type_page_includes_case_insensitive_metadata_values(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Mixed case research metadata',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'Research'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.research'));

        $response->assertOk();
        $response->assertSee('Mixed case research metadata');
    }

    public function test_plans_type_page_shows_only_plan_thoughts(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Plan for Q2',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'plan'],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Not a plan',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'idea'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.plans'));

        $response->assertOk();
        $response->assertSee('Plans', false);
        $response->assertSee('Plan for Q2');
        $response->assertDontSee('Not a plan');
    }

    public function test_plans_type_page_includes_plan_alias_metadata_values(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Plural plans metadata',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'PLANS'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.plans'));

        $response->assertOk();
        $response->assertSee('Plural plans metadata');
    }

    public function test_type_page_shows_empty_state_when_no_matching_thoughts_exist(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Other',
            'parent_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream.plans'));

        $response->assertOk();
        $response->assertSee('No plans yet.', false);
    }

    public function test_disabled_jira_type_is_not_available_in_navigation_mapping(): void
    {
        config(['services.jira.enabled' => false]);

        $this->assertFalse(ThoughtTypeNavigation::isAvailable('jira'));
    }

    public function test_idea_index_email_thought_type_label_links_to_emails_stream_and_destination_ok(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Email card type label',
            'parent_id' => null,
            'source' => 'email',
        ]);

        $href = route('idea.stream.emails');
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('href="'.$href.'"', false);
        $response->assertSee('class="thought-type-badge-link', false);
        $response->assertSee('>Email</a>', false);

        $this->actingAs($user)->get($href)->assertOk();
    }

    public function test_idea_index_jira_thought_type_label_links_to_jira_stream_and_destination_ok(): void
    {
        config(['services.jira.enabled' => true]);

        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Jira card type label',
            'parent_id' => null,
            'source' => 'jira',
        ]);

        $href = route('idea.stream.jira');
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('href="'.$href.'"', false);

        $this->actingAs($user)->get($href)->assertOk();
    }

    public function test_idea_index_research_thought_type_label_links_to_research_stream_and_destination_ok(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Research card type label',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'research'],
        ]);

        $href = route('idea.stream.research');
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('href="'.$href.'"', false);

        $this->actingAs($user)->get($href)->assertOk();
    }

    public function test_idea_index_plan_thought_type_label_links_to_plans_stream_and_destination_ok(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Plan card type label',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => 'plan'],
        ]);

        $href = route('idea.stream.plans');
        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('href="'.$href.'"', false);

        $this->actingAs($user)->get($href)->assertOk();
    }

    public function test_emails_stream_thought_cards_include_type_link_to_canonical_href(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'On emails stream page',
            'parent_id' => null,
            'source' => 'email',
        ]);

        $href = route('idea.stream.emails');
        $response = $this->actingAs($user)->get($href);
        $response->assertOk();
        $response->assertSee('href="'.$href.'"', false);
    }

    public function test_non_routable_thought_does_not_render_placeholder_or_empty_href_links(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'WebOnlyNoRoutableTypeLabel987',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('WebOnlyNoRoutableTypeLabel987');
        $this->assertStringNotContainsString('href="#"', $response->getContent());
        $this->assertStringNotContainsString("href='#'", $response->getContent());
    }

    public function test_malformed_metadata_type_does_not_throw_on_idea_index(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Malformed meta type',
            'parent_id' => null,
            'source' => 'web',
            'metadata' => ['type' => ['nested' => 'x']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));
        $response->assertOk();
        $response->assertSee('Malformed meta type');
    }
}

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
}

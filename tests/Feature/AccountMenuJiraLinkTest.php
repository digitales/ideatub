<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMenuJiraLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_account_menu_shows_jira_link_when_jira_enabled(): void
    {
        config(['services.jira.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('data-testid="account-menu-jira-link"', false);
        $response->assertSee(route('idea.stream.jira'), false);
    }

    public function test_account_menu_hides_jira_link_when_jira_disabled(): void
    {
        config(['services.jira.enabled' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertDontSee('data-testid="account-menu-jira-link"', false);
    }
}

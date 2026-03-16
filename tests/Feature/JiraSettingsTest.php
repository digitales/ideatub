<?php

namespace Tests\Feature;

use App\Jobs\SyncUserJiraActivity;
use App\Models\User;
use App\Models\UserJiraCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.jira.enabled', true);
    }

    public function test_jira_settings_page_requires_auth(): void
    {
        $response = $this->get(route('settings.jira.index'));
        $response->assertRedirect();
    }

    public function test_jira_settings_page_shows_form_when_disconnected(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('settings.jira.index'));
        $response->assertStatus(200);
        $response->assertSee('Connect Jira');
        $response->assertSee('jira_site_url');
    }

    public function test_store_creates_credential_and_redirects(): void
    {
        Http::fake(['*rest/api/3/myself' => Http::response(['accountId' => 'acc-1'], 200)]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('settings.jira.store'), [
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'test-token',
            'jira_email' => 'dev@example.com',
        ]);
        $response->assertRedirect(route('settings.jira.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('user_jira_credentials', [
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
        ]);
    }

    public function test_destroy_removes_credential(): void
    {
        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret',
        ]);
        $response = $this->actingAs($user)->delete(route('settings.jira.destroy'));
        $response->assertRedirect(route('settings.jira.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('user_jira_credentials', ['user_id' => $user->id]);
    }

    public function test_store_fails_validation_when_jira_returns_401(): void
    {
        Http::fake(['*rest/api/3/myself' => Http::response([], 401)]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('settings.jira.store'), [
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'bad-token',
            'jira_email' => 'dev@example.com',
        ]);
        $response->assertRedirect(route('settings.jira.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('user_jira_credentials', ['user_id' => $user->id]);
    }

    public function test_sync_dispatches_job_and_sets_status(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        UserJiraCredential::create([
            'user_id' => $user->id,
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_api_token' => 'secret',
        ]);
        $response = $this->actingAs($user)->post(route('settings.jira.sync'));
        $response->assertRedirect(route('settings.jira.index'));
        $response->assertSessionHas('success');
        Bus::assertDispatched(SyncUserJiraActivity::class);
        $status = \App\Models\UserPreference::get($user, 'jira_sync_status');
        $this->assertIsArray($status);
        $this->assertSame('running', $status['status'] ?? null);
    }

    public function test_status_endpoint_returns_json(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson(route('settings.jira.status'));
        $response->assertStatus(200);
        $response->assertJsonStructure(['status']);
    }
}

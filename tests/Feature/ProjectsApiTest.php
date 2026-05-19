<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\OAuthMcpJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_projects_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'unauthorized']);
    }

    #[Test]
    public function test_lists_projects_for_oauth_user(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrChild($root, 'foo')->for($user)->create();

        $token = $this->mockOAuthAccessToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/projects?elixirr_client_slug=dezeen');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.elixirr_client_slug', 'dezeen');
    }

    #[Test]
    public function test_lists_projects_filtered_by_parent_project_id(): void
    {
        $user = User::factory()->create();
        $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrChild($root, 'foo')->for($user)->create();
        Project::factory()->elixirrChild($root, 'bar')->for($user)->create();

        $token = $this->mockOAuthAccessToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/projects?parent_project_id='.$root->id);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.parent_project_id', (string) $root->id);
    }

    #[Test]
    public function test_projects_rejects_invalid_parent_project_id(): void
    {
        $user = User::factory()->create();
        $token = $this->mockOAuthAccessToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/projects?parent_project_id=not-a-uuid');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'validation_error']);
    }

    #[Test]
    public function test_projects_only_returns_authenticated_users_projects(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
        Project::factory()->elixirrClientRoot('acme')->for($other)->create();

        $token = $this->mockOAuthAccessToken($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.elixirr_client_slug', 'dezeen');
    }

    private function mockOAuthAccessToken(User $user, string $token = 'test-access-token'): string
    {
        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $user->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        return $token;
    }
}

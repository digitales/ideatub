<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\OAuthMcpJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectWorkingMemoryAutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_web_update_persists_working_memory_auto_update(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);

        $this->actingAs($user)
            ->put(route('projects.update', $project), [
                'title' => $project->title,
                'working_memory_auto_update' => '0',
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertFalse($project->fresh()->working_memory_auto_update);
    }

    #[Test]
    public function test_api_update_persists_working_memory_auto_update(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'working_memory_auto_update' => true,
        ]);
        $token = 'test-access-token';

        $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
            $mock->shouldReceive('verifyAccessToken')
                ->once()
                ->with($token)
                ->andReturn([
                    'user_id' => $user->id,
                    'aud' => config('oauth-mcp.resource_api'),
                ]);
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/projects/'.$project->id, [
                'working_memory_auto_update' => false,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.working_memory_auto_update', false);
        $this->assertFalse($project->fresh()->working_memory_auto_update);
    }
}

<?php

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\OAuthMcpJwtService;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mockOAuthAccessToken(User $user, string $token = 'test-access-token'): string
{
    test()->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
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

test('user can pin and unpin project context thought', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'North star briefing']);

    $this->actingAs($user)
        ->post(route('projects.context.store', $project), ['thought_id' => $thought->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    $project->refresh();
    expect((string) $project->context_thought_id)->toBe((string) $thought->id)
        ->and($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Project context', false)
        ->assertSee('North star briefing', false)
        ->assertSee('Pin as context', false);

    $this->actingAs($user)
        ->delete(route('projects.context.destroy', $project))
        ->assertRedirect()
        ->assertSessionHas('success');

    $project->refresh();
    expect($project->context_thought_id)->toBeNull()
        ->and($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();
});

test('pinning replaces previous context thought', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $first = Thought::factory()->create(['user_id' => $user->id, 'content' => 'First context']);
    $second = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Second context']);

    $this->actingAs($user)
        ->post(route('projects.context.store', $project), ['thought_id' => $first->id])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('projects.context.store', $project), ['thought_id' => $second->id])
        ->assertRedirect();

    $project->refresh();
    expect((string) $project->context_thought_id)->toBe((string) $second->id);
});

test('removing pinned thought clears project context', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Pinned member']);

    $this->actingAs($user)
        ->post(route('projects.context.store', $project), ['thought_id' => $thought->id])
        ->assertRedirect();

    $this->actingAs($user)
        ->delete(route('projects.thoughts.destroy', [$project, $thought]))
        ->assertRedirect();

    $project->refresh();
    expect($project->context_thought_id)->toBeNull()
        ->and($project->thoughts()->whereKey($thought->id)->exists())->toBeFalse();
});

test('project working memory api includes pinned context for project scope', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Agent briefing']);

    $project->update(['context_thought_id' => $thought->id]);

    app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'project', (string) $project->id);

    $token = mockOAuthAccessToken($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/thoughts/working-memory?scope_type=project&scope_key='.$project->id)
        ->assertOk()
        ->assertJsonPath('pinned_context.thought_id', (string) $thought->id)
        ->assertJsonPath('pinned_context.content', 'Agent briefing');
});

test('list projects includes context thought id', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project->update(['context_thought_id' => $thought->id]);

    $token = mockOAuthAccessToken($user);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/projects')
        ->assertOk()
        ->assertJsonPath('data.0.context_thought_id', (string) $thought->id);
});

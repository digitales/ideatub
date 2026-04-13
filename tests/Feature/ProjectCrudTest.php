<?php

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest cannot access projects index', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('user can create project and view show', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'title' => 'Alpha',
            'description' => '# Notes',
        ])
        ->assertRedirect();

    $project = Project::query()->where('user_id', $user->id)->first();
    expect($project)->not->toBeNull()
        ->and($project->title)->toBe('Alpha');

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Alpha', false);
});

test('user cannot view another users project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->get(route('projects.show', $project))
        ->assertForbidden();
});

test('user can add and remove thought on project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Member content here']);

    $this->actingAs($user)
        ->post(route('projects.thoughts.store', $project), ['thought_id' => $thought->id])
        ->assertRedirect();

    expect($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('projects.thoughts.destroy', [$project, $thought]))
        ->assertRedirect();

    expect($project->thoughts()->whereKey($thought->id)->exists())->toBeFalse();
});

test('user can soft delete project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and(Project::withTrashed()->whereKey($project->id)->exists())->toBeTrue();
});

test('projects index shows zero ideas when project has no members', function () {
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id, 'title' => 'Empty project']);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Empty project', false)
        ->assertSee('0 ideas', false);
});

test('projects index counts only top-level thoughts linked to project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'Grouped project']);
    $rootA = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);
    $rootB = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);
    $child = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => $rootA->id]);

    $this->actingAs($user);

    foreach ([$rootA, $rootB, $child] as $thought) {
        $this->post(route('projects.thoughts.store', $project), ['thought_id' => $thought->id])
            ->assertRedirect();
    }

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Grouped project', false)
        ->assertSee('2 ideas', false);
});

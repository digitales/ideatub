<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('duplicate elixirr client root is rejected on create', function () {
    $user = User::factory()->create();
    Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'title' => 'Second Dezeen',
            'elixirr_client_slug' => 'dezeen',
        ])
        ->assertSessionHasErrors('elixirr_client_slug');
});

test('duplicate elixirr client root is rejected on update', function () {
    $user = User::factory()->create();
    Project::factory()->elixirrClientRoot('dezeen')->for($user)->create(['title' => 'Original']);
    $other = Project::factory()->for($user)->create(['title' => 'Other']);

    $this->actingAs($user)
        ->put(route('projects.update', $other), [
            'title' => 'Other',
            'elixirr_client_slug' => 'dezeen',
        ])
        ->assertSessionHasErrors('elixirr_client_slug');
});

test('elixirr project slug requires parent on create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'title' => 'Child without parent',
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => 'foo',
        ])
        ->assertSessionHasErrors('parent_project_id');
});

test('elixirr project slug requires parent on update', function () {
    $user = User::factory()->create();
    $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();
    $child = Project::factory()->elixirrChild($root, 'foo')->for($user)->create();

    $this->actingAs($user)
        ->put(route('projects.update', $child), [
            'title' => $child->title,
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => 'foo',
            'parent_project_id' => null,
        ])
        ->assertSessionHasErrors('parent_project_id');
});

test('user can create elixirr child project with parent', function () {
    $user = User::factory()->create();
    $root = Project::factory()->elixirrClientRoot('dezeen')->for($user)->create();

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'title' => 'Foo',
            'elixirr_client_slug' => 'dezeen',
            'elixirr_project_slug' => 'foo',
            'parent_project_id' => (string) $root->id,
        ])
        ->assertRedirect();

    $child = Project::query()
        ->where('user_id', $user->id)
        ->where('elixirr_project_slug', 'foo')
        ->first();

    expect($child)->not->toBeNull()
        ->and($child->parent_project_id)->toBe($root->id)
        ->and($child->elixirr_client_slug)->toBe('dezeen');
});

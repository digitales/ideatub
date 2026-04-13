<?php

use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can create and delete a thought link from detail', function () {
    $user = User::factory()->create();
    $from = Thought::factory()->create(['user_id' => $user->id, 'content' => 'From note']);
    $to = Thought::factory()->create(['user_id' => $user->id, 'content' => 'To note']);

    $this->actingAs($user)
        ->post(route('thoughts.links.store', $from), [
            'to_thought_id' => $to->id,
            'link_type' => ThoughtLinkType::Supports->value,
            'note' => 'Evidence',
        ])
        ->assertRedirect();

    $link = ThoughtLink::query()->first();
    expect($link)->not->toBeNull()
        ->and($link->link_type)->toBe('supports');

    $this->actingAs($user)
        ->delete(route('thoughts.links.destroy', [$from, $link]))
        ->assertRedirect();

    expect(ThoughtLink::query()->count())->toBe(0);
});

test('duplicate link type for same pair fails validation', function () {
    $user = User::factory()->create();
    $from = Thought::factory()->create(['user_id' => $user->id]);
    $to = Thought::factory()->create(['user_id' => $user->id]);

    ThoughtLink::create([
        'user_id' => $user->id,
        'from_thought_id' => $from->id,
        'to_thought_id' => $to->id,
        'link_type' => 'relates_to',
    ]);

    $this->actingAs($user)
        ->post(route('thoughts.links.store', $from), [
            'to_thought_id' => $to->id,
            'link_type' => 'relates_to',
        ])
        ->assertSessionHasErrors('link_type');
});

test('user can attach and detach project from thought detail', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), ['project_id' => $project->id])
        ->assertRedirect();

    expect($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('thoughts.projects.destroy', [$thought, $project]))
        ->assertRedirect();

    expect($project->thoughts()->whereKey($thought->id)->exists())->toBeFalse();
});

test('thought detail page shows projects and link form', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    Thought::factory()->create(['user_id' => $user->id, 'content' => 'Other for picker']);
    Project::factory()->create(['user_id' => $user->id, 'title' => 'My Project']);

    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertSee('Projects', false)
        ->assertSee('Linked thoughts', false)
        ->assertSee('My Project', false)
        ->assertSee('Add to project', false);
});

test('user can create project inline when attaching from thought detail', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => '__new__',
            'new_project_title' => 'Fresh project',
            'new_project_description' => 'Body text',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Project created and thought added.');

    $project = Project::query()->where('user_id', $user->id)->where('title', 'Fresh project')->first();
    expect($project)->not->toBeNull()
        ->and($project->description)->toBe('Body text')
        ->and($project->thoughts()->whereKey($thought->id)->exists())->toBeTrue();
});

test('attaching thought to project it is already in fails validation', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

    $this->actingAs($user)
        ->from(route('thoughts.show', $thought))
        ->post(route('thoughts.projects.store', $thought), ['project_id' => $project->id])
        ->assertRedirect(route('thoughts.show', $thought))
        ->assertSessionHasErrors('project_id');
});

test('inline attach rejects unknown project id', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => '00000000-0000-0000-0000-000000000099',
        ])
        ->assertSessionHasErrors('project_id');
});

test('inline attach rejects title when not creating new project', function () {
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('thoughts.projects.store', $thought), [
            'project_id' => $project->id,
            'new_project_title' => 'Nope',
        ])
        ->assertSessionHasErrors('new_project_title');
});

test('user cannot attach another users project to their thought', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $other->id]);
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->post(route('thoughts.projects.store', $thought), ['project_id' => $project->id])
        ->assertSessionHasErrors('project_id');
});

test('demo mode thought detail hides add to project and project remove controls', function () {
    config(['services.demo_mode.enabled' => true]);

    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'ChipTitle']);
    $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

    session([
        DemoMode::ENABLED_SESSION_KEY => true,
        DemoMode::SEED_SESSION_KEY => 'feat-seed-thought-detail-projects-demo',
    ]);

    $this->actingAs($user)
        ->get(route('thoughts.show', $thought))
        ->assertOk()
        ->assertDontSee('Add to project', false)
        ->assertSee('ChipTitle', false);

    session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
    config(['services.demo_mode.enabled' => false]);
});

<?php

use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
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
        ->assertSee('My Project', false);
});

<?php

use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project attaches thoughts ordered by pivot sort_order', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $first = Thought::factory()->create(['user_id' => $user->id]);
    $second = Thought::factory()->create(['user_id' => $user->id]);

    $project->thoughts()->attach($first->id, ['sort_order' => 0]);
    $project->thoughts()->attach($second->id, ['sort_order' => 1]);

    $orderedIds = $project->thoughts()->pluck('thoughts.id')->all();

    expect($project->thoughts()->count())->toBe(2)
        ->and($orderedIds)->toBe([$first->id, $second->id]);
});

test('user projects and thought links relations', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $link = ThoughtLink::factory()->create(['user_id' => $user->id]);

    expect($user->projects()->pluck('id')->all())->toContain($project->id)
        ->and($user->thoughtLinks()->pluck('id')->all())->toContain($link->id);
});

test('thought links from and to resolve', function () {
    $link = ThoughtLink::factory()->create();

    expect($link->fromThought)->not->toBeNull()
        ->and($link->toThought)->not->toBeNull()
        ->and($link->fromThought->user_id)->toBe($link->user_id)
        ->and($link->toThought->user_id)->toBe($link->user_id);
});

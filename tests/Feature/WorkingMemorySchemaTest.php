<?php

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates working memory tables', function () {
    expect(Schema::hasTable('working_memories'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_versions'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_inputs'))->toBeTrue()
        ->and(Schema::hasColumns('working_memories', [
            'user_id',
            'scope_type',
            'scope_key',
            'latest_version_id',
            'freshness_state',
            'last_refreshed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('working_memory_versions', [
            'working_memory_id',
            'build_type',
            'summary_markdown',
            'structured_sections_json',
            'references_json',
            'citation_coverage',
            'authoring_status',
            'validation_error',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('working_memory_inputs', [
            'working_memory_version_id',
            'thought_id',
            'contribution_type',
            'weight',
        ]))->toBeTrue();
});

it('enforces high-value unique constraints', function () {
    $user = User::factory()->create();

    WorkingMemory::create([
        'user_id' => $user->id,
        'scope_type' => 'project',
        'scope_key' => 'my-app',
        'freshness_state' => 'stale',
    ]);

    $this->expectException(QueryException::class);

    WorkingMemory::create([
        'user_id' => $user->id,
        'scope_type' => 'project',
        'scope_key' => 'my-app',
        'freshness_state' => 'fresh',
    ]);
});

it('enforces unique input rows per version and thought', function () {
    $user = User::factory()->create();

    $memory = WorkingMemory::create([
        'user_id' => $user->id,
        'scope_type' => 'global',
        'scope_key' => 'global',
        'freshness_state' => 'stale',
    ]);

    $version = WorkingMemoryVersion::create([
        'working_memory_id' => $memory->id,
        'build_type' => 'incremental',
        'summary_markdown' => 'summary',
    ]);

    $thought = Thought::factory()->create(['user_id' => $user->id]);

    WorkingMemoryInput::create([
        'working_memory_version_id' => $version->id,
        'thought_id' => $thought->id,
        'contribution_type' => 'primary',
        'weight' => 1.0,
    ]);

    $this->expectException(QueryException::class);

    WorkingMemoryInput::create([
        'working_memory_version_id' => $version->id,
        'thought_id' => $thought->id,
        'contribution_type' => 'supporting',
        'weight' => 0.5,
    ]);
});

it('nulls latest_version_id when latest version is deleted', function () {
    $user = User::factory()->create();

    $memory = WorkingMemory::create([
        'user_id' => $user->id,
        'scope_type' => 'global',
        'scope_key' => 'global',
        'freshness_state' => 'fresh',
    ]);

    $version = WorkingMemoryVersion::create([
        'working_memory_id' => $memory->id,
        'build_type' => 'consolidated',
        'summary_markdown' => 'summary',
    ]);

    $memory->update(['latest_version_id' => $version->id]);
    $version->delete();

    expect($memory->refresh()->latest_version_id)->toBeNull();
});

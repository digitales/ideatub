<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('creates working memory tables', function () {
    $connection = 'working_memory_schema';
    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(Schema::connection($connection)->hasTable('working_memories'))->toBeFalse()
        ->and(Schema::connection($connection)->hasTable('working_memory_versions'))->toBeFalse()
        ->and(Schema::connection($connection)->hasTable('working_memory_inputs'))->toBeFalse();

    Artisan::call('migrate', [
        '--database' => $connection,
        '--path' => base_path('database/migrations/2026_05_05_120000_create_working_memory_tables.php'),
        '--realpath' => true,
        '--force' => true,
    ]);

    expect(Schema::connection($connection)->hasTable('working_memories'))->toBeTrue()
        ->and(Schema::connection($connection)->hasTable('working_memory_versions'))->toBeTrue()
        ->and(Schema::connection($connection)->hasTable('working_memory_inputs'))->toBeTrue()
        ->and(Schema::connection($connection)->hasColumns('working_memories', [
            'scope_type',
            'scope_key',
            'latest_version_id',
            'freshness_state',
            'last_refreshed_at',
        ]))->toBeTrue()
        ->and(Schema::connection($connection)->hasColumns('working_memory_versions', [
            'build_type',
            'summary_markdown',
            'key_concepts_json',
            'active_threads_json',
            'open_questions_json',
            'next_actions_json',
            'confidence_score',
            'source_window_start',
            'source_window_end',
        ]))->toBeTrue()
        ->and(Schema::connection($connection)->hasColumns('working_memory_inputs', [
            'working_memory_version_id',
            'thought_id',
            'contribution_type',
            'weight',
        ]))->toBeTrue();
});

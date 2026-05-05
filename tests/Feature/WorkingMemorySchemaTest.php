<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates working memory tables', function () {
    expect(Schema::hasTable('working_memories'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_versions'))->toBeTrue()
        ->and(Schema::hasTable('working_memory_inputs'))->toBeTrue();
});

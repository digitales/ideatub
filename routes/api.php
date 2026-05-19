<?php

use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\ProjectsApiController;
use App\Http\Controllers\Api\ThoughtsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/mcp', [McpController::class, 'show']);
Route::post('/mcp', McpController::class);
Route::delete('/mcp', [McpController::class, 'destroy']);

// REST API for Custom GPT Actions (OAuth Bearer only)
Route::middleware('auth.oauth.bearer')->group(function (): void {
    Route::get('/projects', [ProjectsApiController::class, 'index']);
});

Route::middleware('auth.oauth.bearer')->prefix('thoughts')->group(function (): void {
    Route::get('/search', [ThoughtsApiController::class, 'search']);
    Route::get('/recent', [ThoughtsApiController::class, 'recent']);
    Route::get('/stats', [ThoughtsApiController::class, 'stats']);
    Route::get('/working-memory', [ThoughtsApiController::class, 'workingMemory']);
    Route::get('/working-memory/versions', [ThoughtsApiController::class, 'workingMemoryVersions']);
    Route::get('/working-memory/versions/{version}', [ThoughtsApiController::class, 'workingMemoryVersion'])->whereUuid('version');
    Route::post('/working-memory/upsert', [ThoughtsApiController::class, 'upsertWorkingMemory']);
    Route::post('/', [ThoughtsApiController::class, 'store']);
});

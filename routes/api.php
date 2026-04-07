<?php

use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\ThoughtsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/mcp', [McpController::class, 'show']);
Route::post('/mcp', McpController::class);
Route::delete('/mcp', [McpController::class, 'destroy']);

// REST API for Custom GPT Actions (OAuth Bearer only)
Route::middleware('auth.oauth.bearer')->prefix('thoughts')->group(function (): void {
    Route::get('/search', [ThoughtsApiController::class, 'search']);
    Route::get('/recent', [ThoughtsApiController::class, 'recent']);
    Route::get('/stats', [ThoughtsApiController::class, 'stats']);
    Route::post('/', [ThoughtsApiController::class, 'store']);
});

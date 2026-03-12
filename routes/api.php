<?php

use App\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

Route::get('/mcp', [McpController::class, 'show']);
Route::post('/mcp', McpController::class);

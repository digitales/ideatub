<?php

use App\Http\Controllers\Api\McpController;
use Illuminate\Support\Facades\Route;

Route::post('/mcp', McpController::class);

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkingMemoryUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.working_memory_ui')) {
            abort(404);
        }

        return $next($request);
    }
}

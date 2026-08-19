<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureJobSearchEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        return $next($request);
    }
}

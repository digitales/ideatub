<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAttentionPulseEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.attention_pulse')) {
            abort(404);
        }

        return $next($request);
    }
}

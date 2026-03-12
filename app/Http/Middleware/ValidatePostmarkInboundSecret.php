<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidatePostmarkInboundSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $secret = config('services.postmark_inbound.webhook_secret');

        if (! is_string($token) || $token === '' || $secret === null || $secret === '' || $token !== $secret) {
            return response('', 404);
        }

        return $next($request);
    }
}

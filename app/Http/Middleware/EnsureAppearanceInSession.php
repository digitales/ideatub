<?php

namespace App\Http\Middleware;

use App\Services\AppearanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAppearanceInSession
{
    public function __construct(
        private AppearanceService $appearance,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->session()->has(AppearanceService::SESSION_KEY)) {
            $this->appearance->hydrateSession($request->user(), $request->session());
        }

        return $next($request);
    }
}

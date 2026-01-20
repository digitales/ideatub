<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOperationLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check for authenticated users on operation tracking routes
        if ($request->routeIs('operations.track') && auth()->check()) {
            $user = auth()->user();
            
            if (!$user->canPerformOperation()) {
                return response()->json([
                    'error' => 'Daily limit reached',
                    'message' => 'You have reached your daily limit of 3 operations. Upgrade to Pro for unlimited access.',
                ], 403);
            }
        }

        return $next($request);
    }
}

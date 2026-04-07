<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\OAuthMcpJwtService;
use App\Support\BearerTokenExtractor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOAuthBearer
{
    public function __construct(
        private OAuthMcpJwtService $jwt
    ) {}

    /**
     * Resolve user from Authorization: Bearer <token> (OAuth JWT). Return 401 if missing or invalid.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('oauth-mcp.enabled', true)) {
            return response()->json(['error' => 'OAuth not enabled'], 503);
        }

        $token = BearerTokenExtractor::fromRequest($request);
        if ($token === null) {
            return $this->unauthorized();
        }

        try {
            $payload = $this->jwt->verifyAccessToken($token);
            $user = User::find($payload['user_id']);
            if ($user === null) {
                return $this->unauthorized();
            }
            $request->setUserResolver(fn () => $user);
            auth()->setUser($user);

            return $next($request);
        } catch (\Throwable) {
            return $this->unauthorized();
        }
    }

    private function unauthorized(): Response
    {
        $resource = config('oauth-mcp.resource_api');
        $scope = config('oauth-mcp.scope', 'ideatub:mcp');

        return response()->json([
            'error' => 'unauthorized',
            'message' => 'Invalid or missing Bearer token. Use OAuth to obtain an access token.',
        ], 401)->header(
            'WWW-Authenticate',
            sprintf('Bearer resource_metadata="%s", scope="%s"', $resource, $scope)
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\OauthMcpAuthorizationCode;
use App\Models\OauthMcpClient;
use App\Services\OAuthMcpJwtService;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OAuthServerController extends Controller
{
    public function __construct(
        private OAuthMcpJwtService $jwt,
        private OAuthMcpRefreshTokenService $refreshTokens,
    ) {}

    /**
     * Dynamic client registration (RFC 7591).
     */
    public function register(Request $request): Response
    {
        $validated = $request->validate([
            'redirect_uris' => 'required|array',
            'redirect_uris.*' => 'required|url',
        ]);

        $redirectUris = $this->normalizeRedirectUris($validated['redirect_uris']);
        if (empty($redirectUris)) {
            return response(['error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URIs must be allowlisted'], 400);
        }

        $client = OauthMcpClient::query()->create([
            'redirect_uris' => $redirectUris,
        ]);

        return response()->json([
            'client_id' => $client->id,
            'redirect_uris' => $client->redirect_uris,
        ], 201);
    }

    /**
     * Authorization endpoint — show consent or redirect to login.
     */
    public function showConsent(Request $request): View|RedirectResponse
    {
        // MCP clients (e.g. Codex CLI) sometimes omit `resource` on the authorize URL; RFC 8707-style
        // resource indicators are still satisfied using the server default audience for this MCP.
        if (! $request->filled('resource')) {
            $request->merge([
                'resource' => config('oauth-mcp.resource'),
            ]);
        }

        $request->validate([
            'response_type' => 'required|in:code',
            'client_id' => 'required|string',
            'redirect_uri' => 'required|url',
            'scope' => 'nullable|string',
            'state' => 'nullable|string',
            'code_challenge' => 'required|string',
            'code_challenge_method' => 'required|in:S256',
            'resource' => 'required|url',
        ]);

        $client = OauthMcpClient::find($request->client_id);
        if (! $client || ! in_array($request->redirect_uri, $client->redirect_uris, true)) {
            return redirect()->route('login')->with('error', 'Invalid OAuth client or redirect URI.');
        }

        if (! auth()->check()) {
            $intended = url('/oauth/authorize').'?'.http_build_query($request->only(
                'response_type', 'client_id', 'redirect_uri', 'scope', 'state',
                'code_challenge', 'code_challenge_method', 'resource'
            ));

            // Must persist until POST /login (redirect()->intended()). Flashing with ->with('url.intended')
            // loses the URL after the GET /login request, so users fell through to idea.index instead of OAuth.
            $request->session()->put('url.intended', $intended);

            return redirect()->route('login')->with('error', 'Please log in to connect IdeaTub.');
        }

        if ($request->has('approve') && $request->approve === '1') {
            return $this->createCodeAndRedirect($request, $client);
        }

        return view('oauth.consent', [
            'authorizeUrl' => url('/oauth/authorize').'?'.http_build_query(array_merge($request->only(
                'response_type', 'client_id', 'redirect_uri', 'scope', 'state',
                'code_challenge', 'code_challenge_method', 'resource'
            ), ['approve' => '1'])),
            'scope' => $request->scope ?? config('oauth-mcp.scope'),
        ]);
    }

    /**
     * Token endpoint — dispatches to the correct grant handler.
     */
    public function token(Request $request): Response
    {
        $grantType = (string) $request->input('grant_type');

        if ($grantType === 'authorization_code') {
            return $this->tokenAuthorizationCode($request);
        }

        if ($grantType === 'refresh_token') {
            return $this->tokenRefresh($request);
        }

        return response()->json([
            'error' => 'unsupported_grant_type',
        ], 400);
    }

    /**
     * RFC 7009 OAuth 2.0 Token Revocation.
     * Always returns 200 regardless of whether the token existed.
     */
    public function revoke(Request $request): Response
    {
        $request->validate([
            'token' => 'required|string',
            'client_id' => 'required|string',
            'token_type_hint' => 'nullable|in:refresh_token,access_token',
        ]);

        $hint = (string) $request->input('token_type_hint', 'refresh_token');
        if ($hint === 'refresh_token') {
            $this->refreshTokens->revokeByRawToken(
                (string) $request->input('token'),
                'user',
                (string) $request->input('client_id'),
            );
        }

        return response()->json([], 200);
    }

    private function tokenAuthorizationCode(Request $request): Response
    {
        $request->validate([
            'grant_type' => 'required|in:authorization_code',
            'code' => 'required|string',
            'redirect_uri' => 'required|url',
            'client_id' => 'required|string',
            'code_verifier' => 'required|string',
            'resource' => 'required|url',
        ]);

        $code = OauthMcpAuthorizationCode::query()
            ->where('code', $request->code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $code) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Invalid or expired code'], 400);
        }

        if ($code->client_id !== $request->client_id || $code->redirect_uri !== $request->redirect_uri) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        if (OAuthMcpJwtService::normalizeResourceUrl((string) $code->resource)
            !== OAuthMcpJwtService::normalizeResourceUrl((string) $request->resource)) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Resource mismatch'], 400);
        }

        $expectedChallenge = hash('sha256', $request->code_verifier, true);
        $expectedChallenge = rtrim(strtr(base64_encode($expectedChallenge), '+/', '-_'), '=');
        if (! hash_equals($expectedChallenge, $code->code_challenge)) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Invalid code_verifier'], 400);
        }

        $code->update(['used_at' => now()]);

        $accessToken = $this->jwt->issueAccessToken($code->user, $request->resource);

        $issued = $this->refreshTokens->issueForCodeExchange(
            $code->user,
            $code->client,
            (string) $request->resource,
            $code->scope ?? config('oauth-mcp.scope'),
            $request,
        );

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => config('oauth-mcp.access_token_ttl_seconds', 3600),
            'refresh_token' => $issued['raw'],
            'scope' => $code->scope ?? config('oauth-mcp.scope'),
        ]);
    }

    private function tokenRefresh(Request $request): Response
    {
        $request->validate([
            'grant_type' => 'required|in:refresh_token',
            'refresh_token' => 'required|string',
            'client_id' => 'required|string',
            'resource' => 'required|url',
            'scope' => 'nullable|string',
        ]);

        try {
            $result = $this->refreshTokens->rotate(
                (string) $request->input('refresh_token'),
                (string) $request->input('client_id'),
                (string) $request->input('resource'),
                $request->input('scope'),
                $request,
            );
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
            if (! in_array($error, ['invalid_grant', 'invalid_scope'], true)) {
                $error = 'invalid_grant';
            }

            return response()->json(['error' => $error], 400);
        }

        $accessToken = $this->jwt->issueAccessToken($result['user'], $result['resource']);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => config('oauth-mcp.access_token_ttl_seconds', 3600),
            'refresh_token' => $result['raw'],
            'scope' => $result['scope'] ?? config('oauth-mcp.scope'),
        ]);
    }

    private function normalizeRedirectUris(array $uris): array
    {
        $allowed = config('oauth-mcp.allowed_redirect_hosts', []);
        $normalized = [];

        foreach ($uris as $uri) {
            $host = parse_url($uri, PHP_URL_HOST);
            if ($host && in_array($host, $allowed, true)) {
                $normalized[] = $uri;
            }
        }

        return $normalized;
    }

    private function createCodeAndRedirect(Request $request, OauthMcpClient $client): RedirectResponse
    {
        $code = Str::random(64);
        $ttl = config('oauth-mcp.authorization_code_ttl_seconds', 600);

        OauthMcpAuthorizationCode::query()->create([
            'code' => $code,
            'client_id' => $client->id,
            'user_id' => auth()->id(),
            'redirect_uri' => $request->redirect_uri,
            'code_challenge' => $request->code_challenge,
            'code_challenge_method' => $request->code_challenge_method,
            'resource' => OAuthMcpJwtService::normalizeResourceUrl((string) $request->resource),
            'scope' => $request->scope ?? config('oauth-mcp.scope'),
            'state' => $request->state,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $params = [
            'code' => $code,
            'state' => $request->state,
        ];

        return redirect()->away($request->redirect_uri.'?'.http_build_query($params));
    }
}

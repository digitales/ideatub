<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConnectedAppsController extends Controller
{
    public function __construct(private OAuthMcpRefreshTokenService $refreshTokens) {}

    public function index(Request $request): View
    {
        $families = OauthMcpRefreshTokenFamily::active()
            ->where('user_id', $request->user()->id)
            ->with('client')
            ->orderByDesc('last_used_at')
            ->orderByDesc('issued_at')
            ->get();

        return view('settings.connected-apps', [
            'families' => $families,
        ]);
    }

    public function destroy(Request $request, OauthMcpRefreshTokenFamily $family): RedirectResponse
    {
        if ($family->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $this->refreshTokens->revokeFamily($family, 'user');

        return redirect()
            ->route('settings.connected-apps.index')
            ->with('success', 'App disconnected.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $count = 0;
        OauthMcpRefreshTokenFamily::active()
            ->where('user_id', $request->user()->id)
            ->get()
            ->each(function (OauthMcpRefreshTokenFamily $family) use (&$count) {
                $this->refreshTokens->revokeFamily($family, 'user');
                $count++;
            });

        return redirect()
            ->route('settings.connected-apps.index')
            ->with('success', $count === 1
                ? '1 connected app disconnected.'
                : "{$count} connected apps disconnected.");
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ResearchShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SharedResearchViewController extends Controller
{
    /**
     * Show shared research (readonly). GET: show content or password form.
     * POST: submit password to unlock; on success set cookie and redirect to GET.
     */
    public function show(Request $request, string $token): View|\Illuminate\Http\RedirectResponse|Response
    {
        $share = ResearchShare::where('token', $token)->first();

        if ($share === null) {
            abort(404, 'Link not found or no longer available.');
        }

        if ($share->isExpired()) {
            abort(410, 'This link has expired.');
        }

        if ($share->password_hash !== null) {
            return $this->handlePasswordProtectedShare($request, $share);
        }

        return $this->renderReadonly($share);
    }

    /**
     * Handle password-protected share: POST = verify password and set cookie; GET = check cookie or show form.
     */
    private function handlePasswordProtectedShare(Request $request, ResearchShare $share): View|\Illuminate\Http\RedirectResponse|Response
    {
        $token = $share->token;
        $cookieName = 'research_share_'.$token;

        if ($request->isMethod('post')) {
            $password = $request->input('password');
            if ($password !== null && Hash::check($password, $share->password_hash)) {
                // Cookie is encrypted (and signed) by Laravel's EncryptCookies middleware by default.
                Cookie::queue($cookieName, $token, 60 * 24, '/', null, null, true);

                return redirect()->route('shared-research.show', ['token' => $token]);
            }

            return response()
                ->view('shared_research.password_form', ['token' => $token, 'error' => 'Incorrect password'], 401);
        }

        $cookieValue = $request->cookie($cookieName);
        if ($cookieValue === $token) {
            return $this->renderReadonly($share);
        }

        return view('shared_research.password_form', ['token' => $token]);
    }

    /**
     * Load thought and sections, return readonly view.
     */
    private function renderReadonly(ResearchShare $share): View
    {
        $thought = $share->thought;

        if ($thought === null) {
            abort(404, 'Link not found or no longer available.');
        }

        $sections = $thought->comments()->orderBy('created_at')->get();

        return view('shared_research.readonly', [
            'root' => $thought,
            'sections' => $sections,
        ]);
    }
}

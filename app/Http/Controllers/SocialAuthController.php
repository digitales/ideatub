<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RegistrationGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect to Google OAuth.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Validate beta access (when required) and start Google OAuth for new signups.
     */
    public function startGoogle(Request $request): RedirectResponse
    {
        return $this->startOAuth($request, 'auth.google');
    }

    /**
     * Handle Google OAuth callback.
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->first();

            if ($user) {
                // Update Google ID if not set
                if (! $user->google_id) {
                    $user->update(['google_id' => $googleUser->id]);
                }
            } else {
                if (! RegistrationGate::canCreateNewUser($request->session())) {
                    return $this->registrationBlockedRedirect();
                }

                // Create new user
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'email_verified_at' => now(),
                ]);

                RegistrationGate::forgetBetaAccessVerified($request->session());
            }

            Auth::login($user, true);

            return redirect()->intended(route('idea.index'));
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Unable to login with Google. Please try again.');
        }
    }

    /**
     * Redirect to GitHub OAuth.
     */
    public function redirectToGithub()
    {
        return Socialite::driver('github')->redirect();
    }

    /**
     * Validate beta access (when required) and start GitHub OAuth for new signups.
     */
    public function startGithub(Request $request): RedirectResponse
    {
        return $this->startOAuth($request, 'auth.github');
    }

    /**
     * Handle GitHub OAuth callback.
     */
    public function handleGithubCallback(Request $request)
    {
        try {
            $githubUser = Socialite::driver('github')->user();

            if (empty($githubUser->email)) {
                return redirect()->route('login')
                    ->with('error', 'Your GitHub account must have a public email address. Please add one in GitHub Settings → Profile.');
            }

            $user = User::where('github_id', $githubUser->id)
                ->orWhere('email', $githubUser->email)
                ->first();

            if ($user) {
                // Update GitHub ID if not set
                if (! $user->github_id) {
                    $user->update(['github_id' => $githubUser->id]);
                }
            } else {
                if (! RegistrationGate::canCreateNewUser($request->session())) {
                    return $this->registrationBlockedRedirect();
                }

                // Create new user
                $user = User::create([
                    'name' => $githubUser->name ?? $githubUser->nickname,
                    'email' => $githubUser->email,
                    'github_id' => $githubUser->id,
                    'email_verified_at' => now(),
                ]);

                RegistrationGate::forgetBetaAccessVerified($request->session());
            }

            Auth::login($user, true);

            return redirect()->intended(route('idea.index'));
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Unable to login with GitHub. Please try again.');
        }
    }

    private function startOAuth(Request $request, string $redirectRoute): RedirectResponse
    {
        if (! RegistrationGate::isOpen()) {
            return redirect()
                ->route('login')
                ->with('error', 'Registration is currently closed.');
        }

        if (RegistrationGate::requiresBetaCode()) {
            $request->validate([
                'beta_code' => ['required', 'string'],
            ]);

            RegistrationGate::assertBetaCode($request->input('beta_code'));
            RegistrationGate::markBetaAccessVerified($request->session());
        }

        return redirect()->route($redirectRoute);
    }

    private function registrationBlockedRedirect(): RedirectResponse
    {
        if (! RegistrationGate::isOpen()) {
            return redirect()
                ->route('login')
                ->with('error', 'Registration is currently closed.');
        }

        return redirect()
            ->route('register')
            ->with('error', 'A valid beta access code is required to create an account.');
    }
}

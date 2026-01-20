<?php

namespace App\Http\Controllers;

use App\Models\User;
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
     * Handle Google OAuth callback.
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->first();

            if ($user) {
                // Update Google ID if not set
                if (!$user->google_id) {
                    $user->update(['google_id' => $googleUser->id]);
                }
            } else {
                // Create new user
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'email_verified_at' => now(),
                ]);
            }

            Auth::login($user, true);

            return redirect()->intended(route('dashboard'));
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
     * Handle GitHub OAuth callback.
     */
    public function handleGithubCallback()
    {
        try {
            $githubUser = Socialite::driver('github')->user();

            $user = User::where('github_id', $githubUser->id)
                ->orWhere('email', $githubUser->email)
                ->first();

            if ($user) {
                // Update GitHub ID if not set
                if (!$user->github_id) {
                    $user->update(['github_id' => $githubUser->id]);
                }
            } else {
                // Create new user
                $user = User::create([
                    'name' => $githubUser->name ?? $githubUser->nickname,
                    'email' => $githubUser->email,
                    'github_id' => $githubUser->id,
                    'email_verified_at' => now(),
                ]);
            }

            Auth::login($user, true);

            return redirect()->intended(route('dashboard'));
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Unable to login with GitHub. Please try again.');
        }
    }
}

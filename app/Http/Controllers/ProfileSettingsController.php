<?php

namespace App\Http\Controllers;

use App\Services\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileSettingsController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.profile', [
            'user' => $request->user(),
            'demoModeEnabled' => app(DemoMode::class)->enabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($validated);

        return redirect()
            ->route('settings.profile.index')
            ->with('success', 'Profile updated.');
    }
}

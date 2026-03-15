<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdeasRevisitSettingsController extends Controller
{
    /**
     * Show the ideas-to-revisit preferences form.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $limit = UserPreference::get($user, 'ideas_to_revisit_limit', 15);
        $minAgeDays = UserPreference::get($user, 'ideas_to_revisit_min_age_days');

        return view('settings.ideas-revisit', [
            'limit' => is_numeric($limit) ? (int) $limit : 15,
            'minAgeDays' => $minAgeDays !== null && $minAgeDays !== '' && is_numeric($minAgeDays) ? (int) $minAgeDays : null,
        ]);
    }

    /**
     * Update ideas-to-revisit preferences.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ideas_to_revisit_limit' => 'required|integer|min:1|max:50',
            'ideas_to_revisit_min_age_days' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        UserPreference::set($user, 'ideas_to_revisit_limit', (int) $validated['ideas_to_revisit_limit']);
        $minAge = $validated['ideas_to_revisit_min_age_days'] ?? null;
        if ($minAge !== null && $minAge !== '') {
            UserPreference::set($user, 'ideas_to_revisit_min_age_days', (int) $minAge);
        } else {
            // Remove preference so service uses "no filter"
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', 'ideas_to_revisit_min_age_days')
                ->delete();
        }

        return redirect()
            ->route('settings.ideas-revisit.index')
            ->with('success', 'Ideas to revisit preferences saved.');
    }
}

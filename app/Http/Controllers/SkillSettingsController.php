<?php

namespace App\Http\Controllers;

use App\Models\MeetingSkill;
use App\Models\ResearchSkill;
use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SkillSettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $researchSkills = ResearchSkill::query()
            ->where('user_id', $user->id)
            ->with('latestVersion')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $meetingSkills = MeetingSkill::query()
            ->where('user_id', $user->id)
            ->with('latestVersion')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $researchAutoRunEnabled = (bool) UserPreference::get($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, false);
        $meetingAutoRunEnabled = (bool) UserPreference::get($user, UserPreference::KEY_MEETING_AUTO_RUN_ENABLED, false);

        return view('settings.skills.index', [
            'researchSkills' => $researchSkills,
            'meetingSkills' => $meetingSkills,
            'researchAutoRunEnabled' => $researchAutoRunEnabled,
            'meetingAutoRunEnabled' => $meetingAutoRunEnabled,
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'research_auto_run_enabled' => ['required', 'boolean'],
            'meeting_auto_run_enabled' => ['required', 'boolean'],
        ]);

        UserPreference::set(
            $request->user(),
            UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED,
            (bool) $validated['research_auto_run_enabled']
        );
        UserPreference::set(
            $request->user(),
            UserPreference::KEY_MEETING_AUTO_RUN_ENABLED,
            (bool) $validated['meeting_auto_run_enabled']
        );

        return redirect()
            ->route('settings.skills.index')
            ->with('success', 'Automation preferences saved.');
    }
}

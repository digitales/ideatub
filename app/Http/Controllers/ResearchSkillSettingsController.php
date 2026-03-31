<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResearchSkillRequest;
use App\Http\Requests\UpdateResearchSkillRequest;
use App\Models\ResearchSkill;
use App\Models\UserPreference;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResearchSkillSettingsController extends Controller
{
    public function __construct(
        private readonly ResearchSkillManager $researchSkillManager
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $skills = ResearchSkill::query()
            ->where('user_id', $user->id)
            ->with('latestVersion')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $autoRunEnabled = (bool) UserPreference::get($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, false);

        return view('settings.research-skills.index', [
            'skills' => $skills,
            'researchAutoRunEnabled' => $autoRunEnabled,
        ]);
    }

    public function create(Request $request): View
    {
        return view('settings.research-skills.create');
    }

    public function store(StoreResearchSkillRequest $request): RedirectResponse
    {
        $this->researchSkillManager->create($request->user(), $request->validated());

        return redirect()
            ->route('settings.research-skills.index')
            ->with('success', 'Research skill created.');
    }

    public function edit(Request $request, ResearchSkill $researchSkill): View
    {
        abort_unless((int) $researchSkill->user_id === (int) $request->user()->id, 403);

        $latest = $this->researchSkillManager->latestVersion($researchSkill);

        return view('settings.research-skills.edit', [
            'skill' => $researchSkill,
            'latest' => $latest,
        ]);
    }

    public function update(UpdateResearchSkillRequest $request, ResearchSkill $researchSkill): RedirectResponse
    {
        $this->researchSkillManager->update($researchSkill, $request->validated());

        return redirect()
            ->route('settings.research-skills.index')
            ->with('success', 'Research skill updated.');
    }

    public function setDefault(Request $request, ResearchSkill $researchSkill): RedirectResponse
    {
        abort_unless((int) $researchSkill->user_id === (int) $request->user()->id, 403);

        $this->researchSkillManager->update($researchSkill, ['is_default' => true]);

        return redirect()
            ->route('settings.research-skills.index')
            ->with('success', 'Default research skill updated.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'research_auto_run_enabled' => ['required', 'boolean'],
        ]);

        UserPreference::set(
            $request->user(),
            UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED,
            (bool) $validated['research_auto_run_enabled']
        );

        return redirect()
            ->route('settings.research-skills.index')
            ->with('success', 'Research preferences saved.');
    }
}

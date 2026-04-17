<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResearchSkillRequest;
use App\Http\Requests\UpdateResearchSkillRequest;
use App\Models\ResearchSkill;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResearchSkillSettingsController extends Controller
{
    public function __construct(
        private readonly ResearchSkillManager $researchSkillManager
    ) {}

    public function create(Request $request): View
    {
        return view('settings.research-skills.create');
    }

    public function store(StoreResearchSkillRequest $request): RedirectResponse
    {
        $this->researchSkillManager->create($request->user(), $request->validated());

        return redirect()
            ->route('settings.skills.index')
            ->withFragment('research-skills')
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
            ->route('settings.skills.index')
            ->withFragment('research-skills')
            ->with('success', 'Research skill updated.');
    }

    public function setDefault(Request $request, ResearchSkill $researchSkill): RedirectResponse
    {
        abort_unless((int) $researchSkill->user_id === (int) $request->user()->id, 403);

        $this->researchSkillManager->update($researchSkill, ['is_default' => true]);

        return redirect()
            ->route('settings.skills.index')
            ->withFragment('research-skills')
            ->with('success', 'Default research skill updated.');
    }
}

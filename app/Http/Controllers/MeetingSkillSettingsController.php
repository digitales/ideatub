<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeetingSkillRequest;
use App\Http\Requests\UpdateMeetingSkillRequest;
use App\Models\MeetingSkill;
use App\Services\Meetings\MeetingSkillManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetingSkillSettingsController extends Controller
{
    public function __construct(
        private readonly MeetingSkillManager $meetingSkillManager
    ) {}

    public function create(Request $request): View
    {
        return view('settings.meeting-skills.create');
    }

    public function store(StoreMeetingSkillRequest $request): RedirectResponse
    {
        $this->meetingSkillManager->create($request->user(), $request->validated());

        return redirect()
            ->route('settings.skills.index')
            ->withFragment('meeting-skills')
            ->with('success', 'Meeting skill created.');
    }

    public function edit(Request $request, MeetingSkill $meetingSkill): View
    {
        abort_unless((int) $meetingSkill->user_id === (int) $request->user()->id, 403);

        $latest = $this->meetingSkillManager->latestVersion($meetingSkill);

        return view('settings.meeting-skills.edit', [
            'skill' => $meetingSkill,
            'latest' => $latest,
        ]);
    }

    public function update(UpdateMeetingSkillRequest $request, MeetingSkill $meetingSkill): RedirectResponse
    {
        $this->meetingSkillManager->update($meetingSkill, $request->validated());

        return redirect()
            ->route('settings.skills.index')
            ->withFragment('meeting-skills')
            ->with('success', 'Meeting skill updated.');
    }

    public function setDefault(Request $request, MeetingSkill $meetingSkill): RedirectResponse
    {
        abort_unless((int) $meetingSkill->user_id === (int) $request->user()->id, 403);

        $this->meetingSkillManager->update($meetingSkill, ['is_default' => true]);

        return redirect()
            ->route('settings.skills.index')
            ->withFragment('meeting-skills')
            ->with('success', 'Default meeting skill updated.');
    }
}

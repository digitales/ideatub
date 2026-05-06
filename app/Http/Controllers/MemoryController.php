<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryAssembler $workingMemoryAssembler,
    ) {}

    public function show(Request $request): View
    {
        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'global',
            'global'
        );

        return view('memory.show', $payload);
    }

    public function showProject(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'project',
            (string) $project->getKey()
        );

        return view('memory.show', array_merge($payload, [
            'scopeTitle' => $project->title,
            'project' => $project,
            'isProjectScope' => true,
        ]));
    }
}

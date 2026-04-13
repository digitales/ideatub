<?php

namespace App\Http\Controllers;

use App\Enums\ThoughtLinkType;
use App\Models\Project;
use App\Models\Thought;
use App\Models\ThoughtLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProjectGraphController extends Controller
{
    public function show(Project $project): View
    {
        $this->authorize('view', $project);

        return view('projects.graph', ['project' => $project]);
    }

    public function data(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $thoughts = $project->thoughts()->orderByPivot('sort_order')->get();
        $memberIds = $thoughts->pluck('id')->all();

        $nodes = $thoughts->map(fn (Thought $t) => [
            'id' => $t->id,
            'label' => Str::limit($t->content, 48),
        ])->values();

        $edges = collect();
        if ($memberIds !== []) {
            $edges = ThoughtLink::query()
                ->where('user_id', $project->user_id)
                ->whereIn('from_thought_id', $memberIds)
                ->whereIn('to_thought_id', $memberIds)
                ->get()
                ->map(function (ThoughtLink $e) {
                    $type = ThoughtLinkType::tryFrom($e->link_type);

                    return [
                        'from' => $e->from_thought_id,
                        'to' => $e->to_thought_id,
                        'label' => $type?->label() ?? $e->link_type,
                    ];
                })
                ->values();
        }

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }
}

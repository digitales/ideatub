<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StripsMemoryGraphLayers;
use App\Models\Project;
use App\Services\Graph\ThoughtGraphQuery;
use App\Services\Graph\ThoughtGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VaultGraphController extends Controller
{
    use StripsMemoryGraphLayers;

    public function __construct(
        private readonly ThoughtGraphService $graphs,
    ) {}

    public function show(Request $request): View
    {
        $projects = Project::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('title')
            ->get(['id', 'title']);

        return view('graph.vault', [
            'projects' => $projects,
            'showSemanticLayer' => config('features.memory_graph_semantic'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = ThoughtGraphQuery::forVault((int) $request->user()->id, $request->query());

        if (! config('features.memory_graph_semantic')) {
            $query->layers = array_values(array_filter(
                $query->layers,
                fn (string $layer) => $layer !== 'semantic'
            ));
        }

        return response()->json($this->graphs->build($query));
    }
}

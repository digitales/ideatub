<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StripsMemoryGraphLayers;
use App\Services\Graph\ThoughtGraphQuery;
use App\Services\Graph\ThoughtGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagGraphController extends Controller
{
    use StripsMemoryGraphLayers;

    public function __construct(
        private readonly ThoughtGraphService $graphs,
    ) {}

    public function show(Request $request): View
    {
        return view('graph.tag_constellation', [
            'tag' => $request->query('tag', ''),
            'showSemanticToggle' => config('features.memory_graph_semantic'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = ThoughtGraphQuery::forTag((int) $request->user()->id, $request->query());

        return response()->json($this->graphs->build($this->stripDisabledGraphLayers($query)));
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StripsMemoryGraphLayers;
use App\Models\Thought;
use App\Services\Graph\ThoughtGraphQuery;
use App\Services\Graph\ThoughtGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ThoughtSemanticGraphController extends Controller
{
    use StripsMemoryGraphLayers;

    public function __construct(
        private readonly ThoughtGraphService $graphs,
    ) {}

    public function show(Thought $thought): View
    {
        $this->authorize('view', $thought);

        return view('graph.thought_semantic', ['thought' => $thought]);
    }

    public function data(Request $request, Thought $thought): JsonResponse
    {
        $this->authorize('view', $thought);

        $query = ThoughtGraphQuery::forSemantic(
            (int) $request->user()->id,
            $thought->id,
            $request->query(),
        );

        return response()->json($this->graphs->build($this->stripDisabledGraphLayers($query)));
    }
}

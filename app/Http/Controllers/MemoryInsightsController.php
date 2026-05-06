<?php

namespace App\Http\Controllers;

use App\Services\WorkingMemory\WorkingMemoryAssembler;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryInsightsController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryAssembler $workingMemoryAssembler,
    ) {}

    public function show(Request $request): View
    {
        $payload = $this->workingMemoryAssembler->forScope(
            (int) $request->user()->id,
            'insights',
            'global'
        );

        return view('memory.insights', [
            'payload' => $payload,
        ]);
    }
}

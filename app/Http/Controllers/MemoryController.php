<?php

namespace App\Http\Controllers;

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
}

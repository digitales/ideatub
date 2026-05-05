<?php

namespace App\Http\Controllers;

use App\Services\WorkingMemory\MemoryInsightsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryInsightsController extends Controller
{
    public function __construct(
        private readonly MemoryInsightsService $memoryInsightsService,
    ) {}

    public function show(Request $request): View
    {
        $markdown = $this->memoryInsightsService->markdownForUser((int) $request->user()->id);

        return view('memory.insights', [
            'markdown' => $markdown,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\WorkingMemory\WorkingMemoryScopesIndexBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryScopesController extends Controller
{
    public function __construct(
        private readonly WorkingMemoryScopesIndexBuilder $indexBuilder,
    ) {}

    public function index(Request $request): View
    {
        return view('memory.scopes.index', $this->indexBuilder->build((int) $request->user()->id));
    }
}

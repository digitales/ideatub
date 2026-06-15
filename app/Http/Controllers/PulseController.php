<?php

namespace App\Http\Controllers;

use App\Services\Attention\AttentionOverviewBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PulseController extends Controller
{
    public function __construct(
        private readonly AttentionOverviewBuilder $overviewBuilder,
    ) {}

    public function show(Request $request): View
    {
        $overview = $this->overviewBuilder->build((int) $request->user()->id);

        return view('pulse.show', [
            'overview' => $overview,
        ]);
    }
}

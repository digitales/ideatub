<?php

namespace App\Http\Controllers;

use App\Services\AppearanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppearanceController extends Controller
{
    public function __construct(
        private AppearanceService $appearance,
    ) {}

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', 'in:'.implode(',', $this->appearance->allowed())],
        ]);

        $this->appearance->set(
            $request->user(),
            $request->session(),
            $validated['appearance'],
        );

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\DemoMode;
use Illuminate\Http\RedirectResponse;

class DemoModeController extends Controller
{
    public function enable(): RedirectResponse
    {
        abort_unless(config('services.demo_mode.enabled'), 404);

        app(DemoMode::class)->enable();

        return redirect()
            ->route('idea.index')
            ->with('success', __('Demo mode enabled.'));
    }

    public function disable(): RedirectResponse
    {
        abort_unless(config('services.demo_mode.enabled'), 404);

        app(DemoMode::class)->disable();

        return redirect()
            ->route('idea.index')
            ->with('success', __('Demo mode disabled.'));
    }
}

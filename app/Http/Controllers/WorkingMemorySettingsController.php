<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkingMemorySettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $resolver = app(WorkingMemoryConsolidationWindowResolver::class);
        $raw = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS);

        return view('settings.working-memory', [
            'effectiveDays' => $resolver->effectiveDaysForUserId((int) $user->id),
            'overrideDays' => ($raw !== null && $raw !== '' && is_numeric($raw)) ? (int) $raw : null,
            'defaultDays' => $resolver->configuredDefault(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'working_memory_consolidation_window_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $user = $request->user();
        $value = $validated['working_memory_consolidation_window_days'] ?? null;

        if ($value === null) {
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS)
                ->delete();
        } else {
            UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, (int) $value);
        }

        return redirect()->route('settings.working-memory.index')->with('success', 'Working memory settings saved.');
    }
}

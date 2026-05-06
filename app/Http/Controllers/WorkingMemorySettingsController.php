<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Services\WorkingMemory\ForcedTagResolver;
use App\Services\WorkingMemory\WorkingMemoryConsolidationWindowResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkingMemorySettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $windowResolver = app(WorkingMemoryConsolidationWindowResolver::class);
        $forcedTagResolver = app(ForcedTagResolver::class);
        $rawWindowOverride = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS);
        $rawForcedTags = UserPreference::get($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS);
        $normalizedForcedTags = $forcedTagResolver->normalizeTags(
            is_array($rawForcedTags) || is_string($rawForcedTags) ? $rawForcedTags : null
        );

        return view('settings.working-memory', [
            'effectiveDays' => $windowResolver->effectiveDaysForUserId((int) $user->id),
            'overrideDays' => ($rawWindowOverride !== null && $rawWindowOverride !== '' && is_numeric($rawWindowOverride))
                ? (int) $rawWindowOverride
                : null,
            'defaultDays' => $windowResolver->configuredDefault(),
            'forcedTagsValue' => implode("\n", $normalizedForcedTags),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'working_memory_consolidation_window_days' => 'nullable|integer|min:1|max:3650',
            'working_memory_forced_tags' => 'nullable|string',
        ]);

        $user = $request->user();
        $value = $validated['working_memory_consolidation_window_days'] ?? null;
        $forcedTagResolver = app(ForcedTagResolver::class);
        $normalizedForcedTags = $forcedTagResolver->normalizeTags($validated['working_memory_forced_tags'] ?? null);

        if ($value === null) {
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS)
                ->delete();
        } else {
            UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS, (int) $value);
        }

        if ($normalizedForcedTags === []) {
            UserPreference::query()
                ->where('user_id', $user->id)
                ->where('key', UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS)
                ->delete();
        } else {
            UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS, $normalizedForcedTags);
        }

        return redirect()->route('settings.working-memory.index')->with('success', 'Working memory settings saved.');
    }
}

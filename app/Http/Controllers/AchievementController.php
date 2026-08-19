<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('viewAny', Achievement::class);

        $achievements = Achievement::query()
            ->where('user_id', auth()->id())
            ->when($request->filled('tag'), fn ($q) => $q->where('tag', $request->string('tag')))
            ->orderBy('tag')
            ->get();

        return view('job_pipeline.achievements.index', ['achievements' => $achievements]);
    }

    public function store(Request $request)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('create', Achievement::class);

        $request->validate(['tag' => 'required|string|max:100', 'bullet_text' => 'required|string']);

        Achievement::query()->create([
            'user_id' => auth()->id(),
            'tag' => $request->string('tag'),
            'bullet_text' => $request->string('bullet_text'),
            'times_used' => 0,
        ]);

        return back()->with('success', 'Achievement added.');
    }

    public function update(Request $request, Achievement $achievement)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $achievement);

        $request->validate(['tag' => 'required|string|max:100', 'bullet_text' => 'required|string']);
        $achievement->update($request->only('tag', 'bullet_text'));

        return back()->with('success', 'Achievement updated.');
    }

    public function retire(Achievement $achievement)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $achievement);

        $achievement->update(['retired_at' => now()]);

        return back()->with('success', 'Achievement retired.');
    }
}

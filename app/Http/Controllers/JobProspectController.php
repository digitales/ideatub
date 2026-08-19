<?php

namespace App\Http\Controllers;

use App\Models\JobProspect;
use App\Services\JobSearch\ProspectPromotionService;
use Illuminate\Http\Request;

class JobProspectController extends Controller
{
    public function index()
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('viewAny', JobProspect::class);

        $prospects = JobProspect::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', ['new', 'scored', 'shortlisted'])
            ->orderByDesc('discovered_at')
            ->get();

        return view('job_pipeline.prospects.index', ['prospects' => $prospects]);
    }

    public function update(Request $request, JobProspect $prospect)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $prospect);

        $request->validate(['notes' => ['nullable', 'string']]);
        $prospect->update(['notes' => $request->input('notes')]);

        return response()->json(['ok' => true]);
    }

    public function shortlist(JobProspect $prospect)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $prospect);

        $prospect->update(['status' => 'shortlisted']);

        return back()->with('success', 'Shortlisted.');
    }

    public function markApplied(JobProspect $prospect, ProspectPromotionService $promotionService)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $prospect);

        $promotionService->promote($prospect, 'applied');

        return back()->with('success', 'Marked applied.');
    }

    public function dismiss(JobProspect $prospect)
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        $this->authorize('update', $prospect);

        $prospect->update(['status' => 'dismissed']);

        return back()->with('success', 'Dismissed.');
    }
}

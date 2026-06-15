<?php

namespace App\Http\Controllers;

use App\Models\CommitmentItem;
use App\Services\Commitments\CommitmentItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommitmentController extends Controller
{
    public function markDone(CommitmentItem $commitmentItem, CommitmentItemService $service): RedirectResponse
    {
        $this->authorize('update', $commitmentItem);

        $service->markDone($commitmentItem);

        return redirect()->route('pulse.show')->with('success', 'Commitment marked done.');
    }

    public function snooze(Request $request, CommitmentItem $commitmentItem, CommitmentItemService $service): RedirectResponse
    {
        $this->authorize('update', $commitmentItem);

        $validated = $request->validate([
            'preset' => 'required|string|in:tomorrow,next_week',
        ]);

        $service->snooze($commitmentItem, $validated['preset']);

        return redirect()->route('pulse.show')->with('success', 'Commitment snoozed.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Models\ThoughtSuggestedLink;
use Illuminate\Http\RedirectResponse;

class ThoughtSuggestedLinkController extends Controller
{
    public function dismiss(Thought $thought, ThoughtSuggestedLink $suggestion): RedirectResponse
    {
        $this->authorize('view', $thought);

        if ($suggestion->from_thought_id !== $thought->id || (int) $suggestion->user_id !== (int) auth()->id()) {
            abort(404);
        }

        $suggestion->update(['dismissed_at' => now()]);

        return back()->with('success', 'Suggestion dismissed.');
    }
}

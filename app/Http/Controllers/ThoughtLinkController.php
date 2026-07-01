<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreThoughtLinkRequest;
use App\Models\Thought;
use App\Models\ThoughtLink;
use App\Models\ThoughtSuggestedLink;
use Illuminate\Http\RedirectResponse;

class ThoughtLinkController extends Controller
{
    public function store(StoreThoughtLinkRequest $request, Thought $thought): RedirectResponse
    {
        $validated = $request->validated();
        $linkType = $validated['link_type'];
        if ($linkType instanceof \BackedEnum) {
            $linkType = $linkType->value;
        }

        ThoughtLink::create([
            'user_id' => $request->user()->id,
            'from_thought_id' => $thought->id,
            'to_thought_id' => $validated['to_thought_id'],
            'link_type' => $linkType,
            'note' => $validated['note'] ?? null,
        ]);

        if (! empty($validated['suggestion_id'])) {
            ThoughtSuggestedLink::query()
                ->where('id', $validated['suggestion_id'])
                ->where('from_thought_id', $thought->id)
                ->where('user_id', $request->user()->id)
                ->update(['promoted_at' => now()]);
        }

        return back()->with('success', 'Link added.');
    }

    public function destroy(Thought $thought, ThoughtLink $thoughtLink): RedirectResponse
    {
        $this->authorize('delete', $thoughtLink);

        if ($thoughtLink->from_thought_id !== $thought->id && $thoughtLink->to_thought_id !== $thought->id) {
            abort(404);
        }

        if ((int) $thought->user_id !== (int) auth()->id()) {
            abort(403);
        }

        $thoughtLink->delete();

        return back()->with('success', 'Link removed.');
    }
}

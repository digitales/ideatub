<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class IdeaController extends Controller
{
    /**
     * Idea/thoughts index: recent thoughts (top-level only) and optional search.
     * Recent list uses Thought::topLevel() so only parent_id IS NULL thoughts are shown.
     */
    public function index(Request $request): View
    {
        $thoughts = Thought::query()
            ->where('user_id', $request->user()->id)
            ->topLevel()
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->latest()
            ->take(20)
            ->get();

        $replyingTo = null;
        if ($request->filled('parent_id')) {
            $parent = Thought::where('user_id', $request->user()->id)->find($request->parent_id);
            if ($parent) {
                $replyingTo = $parent;
            }
        }

        return view('idea.index', [
            'thoughts' => $thoughts,
            'replyingTo' => $replyingTo,
        ]);
    }

    /**
     * Store a new thought (or comment when parent_id is set).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'parent_id' => 'nullable|integer|exists:thoughts,id',
        ]);

        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = Thought::find($validated['parent_id']);
            if (! $parent) {
                abort(404, 'Parent thought not found.');
            }
            $this->authorize('comment', $parent);
        }

        Thought::create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'metadata' => [],
            'parent_id' => $parent?->id,
        ]);

        return redirect()->route('ideas.index')->with('status', 'Thought saved.');
    }
}

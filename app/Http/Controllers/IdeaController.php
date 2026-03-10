<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdeaController extends Controller
{
    private const RECENT_LIMIT = 20;

    private const SEARCH_LIMIT = 20;

    public function __construct(
        private OpenRouterService $openRouter
    ) {}

    /**
     * Idea index: semantic search when ?q= present, otherwise recent thoughts.
     */
    public function index(Request $request): View
    {
        $query = $request->input('q');
        $query = is_string($query) ? trim($query) : '';

        if ($query !== '') {
            $embedding = $this->openRouter->embed($query);
            $thoughts = Thought::query()
                ->where('user_id', auth()->id())
                ->nearestTo($embedding, self::SEARCH_LIMIT)
                ->get();
        } else {
            $thoughts = Thought::query()
                ->where('user_id', auth()->id())
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->get();
        }

        return view('idea.index', [
            'thoughts' => $thoughts,
            'query' => $query !== '' ? $query : null,
        ]);
    }

    /**
     * Store a new thought: validate, embed, extract metadata, save. Redirect back with success or JSON.
     */
    public function store(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
        ]);
        $content = $validated['content'];

        $embedding = $this->openRouter->embed($content);
        $metadata = $this->openRouter->extractMetadata($content);

        Thought::create([
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => auth()->id(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Thought saved.']);
        }

        return redirect()->route('idea.index')->with('success', 'Thought saved.');
    }
}
